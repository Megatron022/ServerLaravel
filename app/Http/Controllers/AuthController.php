<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\MagicService;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller {

    protected $magicService;

    public function __construct(MagicService $magicService) {
        $this->magicService = $magicService;
    }

    /********************************************
    *
    * Functions to sign in users.
    *
    *********************************************/

    public function in(Request $request) {
        try {
            switch ($this->magicService->getUserType($request)) {
                case 'google':
                    return $this->inGoogle($request);
                case 'phone':
                    return $this->inPhone($request);
                case 'guest':
                    return $this->inGuest($request);
                default:
                    return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', null, $request);
            }
        } catch (Exception $e) {
            return $this->magicService->getErrorResponse('INTERNAL_SERVER_ERROR', $e->getMessage(), $request);
        }
    }

    // Google Sign In

    private function inGoogle(Request $request) {
        $validator = Validator::make($request->headers->all(), [
            'x-account-type' => 'required',
            'o-auth-token' => 'required'
        ]);

        if ($validator->fails()) return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', $validator->errors(), $request);

        $payload = $this->magicService->getGooglePayload($request);

        if (!$payload) return $this->magicService->getErrorResponse('INVALID_AUTH_TOKEN', null, $request);

        $user = User::withTrashed()->where('email', $payload['email'])->first();

        if ($user && $user->trashed()) {

            $additional = [];

            if ($user->email) $additional['user']['email'] = $user->email;
            if ($user->phone) $additional['user']['phone'] = $user->phone;
            if ($user->photo) $additional['user']['photo'] = $user->photo;
            if ($user->name) $additional['user']['name'] = $user->name;

            return $this->magicService->getErrorResponse('ACCOUNT_WAS_DELETED', null, $request, $additional);
        }

        if (!$user) {
            $user = new User();
            $user->name = $payload['name'];
            $user->email = $payload['email'];
            $user->photo = $payload['picture'];
            $user->google = $payload['sub'];
            $user->assignRole('customer');
            $user->save();
        }

        if (!$user->hasRole('customer')) {
            return self::getErrorResponse('MISSING_REQUIRED_PERMISSIONS', null, $request);
        }

        $body = [
            'user' => $this->magicService->getUserResponse($request, $user, [
                'name' => $user->name,
                'email' => $user->email,
                'photo' => $user->photo
            ])
        ];

        if ($user->phone) $body['user']['phone'] = $user->phone;

        return $this->magicService->getSuccessResponse($body, $request);
    }

    // Phone Sign In

    private function inPhone(Request $request) {

        $validator = Validator::make($request->json()->all(), [
            'phone' => 'required|integer'
        ]);

        if ($validator->fails()) return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', $validator->errors(), $request);

        $user = User::withTrashed()->where('phone', $request->json('phone'))->first();

        if ($user && $user->trashed()) {

            $additional = [];

            if ($user->email) $additional['user']['email'] = $user->email;
            if ($user->phone) $additional['user']['phone'] = $user->phone;
            if ($user->photo) $additional['user']['photo'] = $user->photo;
            if ($user->name) $additional['user']['name'] = $user->name;

            return $this->magicService->getErrorResponse('ACCOUNT_WAS_DELETED', null, $request, $additional);
        }

        if (!$user) {
            $user = new User();
            $user->name = 'User';
            $user->phone = $request->json('phone');
            $user->assignRole('customer');
            $user->save();
        }

        if (!$user->hasRole('customer')) {
            return self::getErrorResponse('MISSING_REQUIRED_PERMISSIONS', null, $request);
        }

        $token = JWTAuth::fromUser($user);

        $body = [
            'user' => $this->magicService->getUserResponse($request, $user, [
                'name' => $user->name,
                'phone' => $user->phone,
                'o-auth-token' => 'Bearer ' . $token,
                'o-auth-expires' => JWTAuth::setToken($token)->getPayload()->get('exp') * 1000
            ])
        ];

        if ($user->email) $body['user']['email'] = $user->email;
        if ($user->photo) $body['user']['photo'] = $user->photo;

        return $this->magicService->getSuccessResponse($body, $request);
    }

    // Guest Sign In

    private function inGuest(Request $request) {

        $validator = Validator::make($request->json()->all(), [
            'guest' => 'required|integer'
        ]);

        if ($validator->fails()) return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', $validator->errors(), $request);

        if (User::withTrashed()->where('guest', $request->guest)->exists()) return $this->magicService->getErrorResponse('ACCOUNT_ALREADY_EXISTS', null, $request);

        $user = new User();
        $user->name = 'User';
        $user->guest = $request->json('guest');
        $user->assignRole('customer');
        $user->save();

        if (!$user->hasRole('customer')) {
            return self::getErrorResponse('MISSING_REQUIRED_PERMISSIONS', null, $request);
        }

        $token = JWTAuth::fromUser($user);

        $body = [
            'user' => $this->magicService->getUserResponse($request, $user, [
                'name' => $user->name,
                'o-auth-token' => 'Bearer ' . $token,
                'o-auth-expires' => JWTAuth::setToken($token)->getPayload()->get('exp') * 1000
            ])
        ];

        return $this->magicService->getSuccessResponse($body, $request);
    }

    /********************************************
    *
    * Functions to refresh JWT tokens.
    *
    *********************************************/

    public function refresh(Request $request) {
        switch ($this->magicService->getUserType($request)) {
            case 'phone':
                return $this->refreshPhone($request);
            case 'guest':
                return $this->refreshGuest($request);
            default:
                return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', null, $request);
        }
    }

    // Phone Refresh

    private function refreshPhone(Request $request) {

        $validator = Validator::make($request->json()->all(), [
            'phone' => 'required|numeric'
        ]);

        if ($validator->fails()) return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', $validator->errors(), $request);

        try {

            $user = User::where('phone', $request->json('phone'))->first();

            if (!$user) return $this->magicService->getErrorResponse('ACCOUNT_NOT_FOUND', null, $request);

            if (!$user->hasRole('customer')) {
                return self::getErrorResponse('MISSING_REQUIRED_PERMISSIONS', null, $request);
            }

            $token = JWTAuth::fromUser($user);

            $body = [
                'user' => $this->magicService->getUserResponse($request, $user, [
                    'o-auth-token' => 'Bearer ' . $token,
                    'o-auth-expires' => JWTAuth::setToken($token)->getPayload()->get('exp') * 1000
                ])
            ];

            return $this->magicService->getSuccessResponse($body, $request);

        } catch (JWTException $e) {
            return $this->magicService->getErrorResponse('INTERNAL_SERVER_ERROR', $e->getMessage(), $request);
        }
    }

    // Guest Refresh

    private function refreshGuest(Request $request) {

        $validator = Validator::make($request->json()->all(), [
            'guest' => 'required|string'
        ]);

        if ($validator->fails()) return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', $validator->errors(), $request);

        $user = User::where('guest', $request->json('guest'))->first();

        if (!$user) return $this->magicService->getErrorResponse('ACCOUNT_NOT_FOUND', null, $request);

        if (!$user->hasRole('customer')) {
            return self::getErrorResponse('MISSING_REQUIRED_PERMISSIONS', null, $request);
        }

        $token = JWTAuth::fromUser($user);

        $body = [
            'user' => $this->magicService->getUserResponse($request, $user, [
                'name' => $user->name,
                'o-auth-token' => 'Bearer ' . $token,
                'o-auth-expires' => JWTAuth::setToken($token)->getPayload()->get('exp') * 1000
            ])
        ];

        return $this->magicService->getSuccessResponse($body, $request);
    }

    /********************************************
    *
    * Functions to recover accounts.
    *
    *********************************************/

    public function recover(Request $request) {
        switch ($this->magicService->getUserType($request)) {
            case 'google':
                return $this->recoverGoogle($request);
            case 'phone':
                return $this->recoverPhone($request);
            default:
                return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', null, $request);
        }
    }

    // Google Recover

    private function recoverGoogle(Request $request) {
        $validator = Validator::make($request->headers->all(), [
            'x-account-type' => 'required',
            'o-auth-token' => 'required'
        ]);

        if ($validator->fails()) return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', $validator->errors(), $request);

        $payload = $this->magicService->getGooglePayload($request);

        if (!$payload) return $this->magicService->getErrorResponse('INVALID_AUTH_TOKEN', null, $request);

        $user = User::withTrashed()->where('email', $payload['email'])->first();

        if (!$user) return $this->magicService->getErrorResponse('ACCOUNT_NOT_FOUND', null, $request);

        if (!$user->hasRole('customer')) {
            return self::getErrorResponse('MISSING_REQUIRED_PERMISSIONS', null, $request);
        }

        if (!$user->trashed()) return $this->magicService->getErrorResponse('ACCOUNT_ALREADY_EXISTS', null, $request);

        $user->restore();

        $body = [
            'user' => $this->magicService->getUserResponse($request, $user),
            'message' => 'Account was recovered successfully.'
        ];

        return $this->magicService->getSuccessResponse($body, $request);
    }

    // Phone Recover

    private function recoverPhone(Request $request) {

        $validator = Validator::make($request->json()->all(), [
            'phone' => 'required|numeric'
        ]);

        if ($validator->fails()) return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', $validator->errors(), $request);

        $user = User::withTrashed()->where('phone', $request->json('phone'))->first();

        if (!$user) return $this->magicService->getErrorResponse('ACCOUNT_NOT_FOUND', null, $request);

        if (!$user->hasRole('customer')) {
            return self::getErrorResponse('MISSING_REQUIRED_PERMISSIONS', null, $request);
        }

        if (!$user->trashed()) return $this->magicService->getErrorResponse('ACCOUNT_ALREADY_EXISTS', null, $request);

        $user->restore();

        $token = JWTAuth::fromUser($user);

        $body = [
            'user' => $this->magicService->getUserResponse($request, $user, [
                'o-auth-token' => 'Bearer ' . $token,
                'o-auth-expires' => JWTAuth::setToken($token)->getPayload()->get('exp') * 1000
            ]),
            'message' => 'Account was recovered successfully.'
        ];

        return $this->magicService->getSuccessResponse($body, $request);
    }

}
