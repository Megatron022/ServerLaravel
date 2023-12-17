<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\MagicService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class UserController extends Controller {
    
    protected $magicService;
    
    public function __construct(MagicService $magicService) {
        $this->magicService = $magicService;
    }
    
    /********************************************
    *
    * Functions to sign out users.
    *
    *********************************************/
    
    public function out(Request $request) {
        switch ($this->magicService->getUserType($request)) {
            case 'google':
                return $this->outGoogle($request);
            case 'phone':
                return $this->outPhone($request);
            case 'guest':
                return $this->outGuest($request);
            default:
                return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', null, $request);
        }
    }
    
    // Google Sign Out
    
    private function outGoogle(Request $request) {
        
        $user = $this->magicService->getUser($request);
        
        if (class_basename($user) !== 'User') return $user;
        
        $payload = $this->magicService->getGooglePayload($request);
        
        $this->magicService->getGoogleClient()->revokeToken($request->header('o-auth-token'));
        
        $body = [
            'user' => $this->magicService->getUserResponse($request, $user),
            'message' => 'Successfully logged out of account.'
        ];
        
        return $this->magicService->getSuccessResponse($body, $request);
    }
    
    // Phone Sign Out
    
    private function outPhone(Request $request) {
        
        $user = $this->magicService->getUser($request);
        
        if (class_basename($user) !== 'User') return $user;
        
        JWTAuth::setToken($this->magicService->cleanToken($request))->invalidate();
        
        $body = [
            'user' => $this->magicService->getUserResponse($request, $user),
            'message' => 'Successfully logged out of account.'
        ];
        
        return $this->magicService->getSuccessResponse($body, $request);
    }
    
    // Guest Sign Out
    
    private function outGuest(Request $request) {
        
        $user = $this->magicService->getUser($request);
        
        if (class_basename($user) !== 'User') return $user;
        
        $guest = $user->guest;
        
        if (!$user->email && !$user->phone) {
            $user->status = User::STATUS_INACTIVE;
            $user->delete();
        }
        
        JWTAuth::setToken($this->magicService->cleanToken($request))->invalidate();
        
        $body = [
            'user' => $this->magicService->getUserResponse($request, $user),
            'message' => 'Successfully logged out of account.'
        ];
        
        return $this->magicService->getSuccessResponse($body, $request);
    }
    
    /********************************************
    *
    * Functions to link users.
    *
    *********************************************/
    
    public function link(Request $request) {
        switch ($this->magicService->getUserType($request)) {
            case 'google':
                return $this->linkPhoneWithGoogle($request);
            case 'phone':
                return $this->linkGoogleWithPhone($request);
            case 'guest':
                return $this->linkGuest($request);
            default:
                return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', null, $request);
        }
    }
    
    private function linkPhoneWithGoogle(Request $request) {
        
        $validator = Validator::make($request->json()->all(), [
            'payload' => 'required|integer'
        ]);
        
        if ($validator->fails()) return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', $validator->errors(), $request);
        
        try {
            
            $user = $this->magicService->getUser($request);
            
            if (class_basename($user) !== 'User') return $user;
            
            $payload = $this->magicService->getGooglePayload($request);
            
            if (User::where('phone', $request->json('payload'))->first()) {
                return $this->magicService->getErrorResponse('ACCOUNT_ALREADY_EXISTS', null, $request);
            }
    
            $user->phone = $request->json('payload');
            $user->save();
            
            $body = [
                'user' => $this->magicService->getUserResponse($request, $user, [
                    'name' => $user->name,
                    'email' => $user->email,
                    'photo' => $user->photo,
                    'phone' => $user->phone
                ]),
                'message' => 'Successfully linked Google Account and Phone Number.'
            ];
            
            return $this->magicService->getSuccessResponse($body, $request);
            
        } catch (Exception $e) {
            
            return $this->magicService->getErrorResponse('INTERNAL_SERVER_ERROR', $e->getMessage(), $request);
            
        }
    }
    
    private function linkGoogleWithPhone(Request $request) {
        
        $validator = Validator::make($request->json()->all(), [
            'payload' => 'required|string'
        ]);
        
        if ($validator->fails()) return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', $validator->errors(), $request);
        
        try {
            
            $user = $this->magicService->getUser($request);
            
            if (class_basename($user) !== 'User') return $user;
            
            $payload = $this->magicService->getGooglePayloadFromToken($request->json('payload'));
            
            if (!$payload) return $this->magicService->getErrorResponse('INVALID_AUTH_TOKEN', null, $request);
            
            if (User::where('email', $payload['email'])->first()) {
                return $this->magicService->getErrorResponse('ACCOUNT_ALREADY_EXISTS', null, $request);
            }
            
            $user->name = $payload['name'];
            $user->email = $payload['email'];
            $user->photo = $payload['picture'];
            $user->google = $payload['sub'];
            $user->save();
            
            $body = [
                'user' => $this->magicService->getUserResponse($request, $user, [
                    'name' => $user->name,
                    'email' => $user->email,
                    'photo' => $user->photo,
                    'phone' => $user->phone,
                    'google' => $user->google
                ]),
                'message' => 'Successfully linked Phone Number and Google Account.'
            ];
            
            return $this->magicService->getSuccessResponse($body, $request);
            
        } catch (Exception $e) {
            
            return $this->magicService->getErrorResponse('INTERNAL_SERVER_ERROR', $e->getMessage(), $request);
            
        }
    }
    
    private function linkGuest(Request $request) {
        
        $validator = Validator::make($request->json()->all(), [
            'type' => 'required|string'
        ]);
        
        if ($validator->fails()) return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', $validator->errors(), $request);
        
        try {
            
            $user = $this->magicService->getUser($request);
            
            if (class_basename($user) !== 'User') return $user;
            
            switch ($request->json('type')) {
                
                case 'google':
                    
                    $validator = Validator::make($request->json()->all(), [
                        'payload' => 'required|string'
                    ]);
                    
                    if ($validator->fails()) return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', $validator->errors(), $request);
                    
                    $payload = $this->magicService->getGooglePayloadFromToken($request->json('payload'));
                    
                    if (!$payload) return $this->magicService->getErrorResponse('INVALID_AUTH_TOKEN', null, $request);
                    
                    if (User::where('email', $payload['email'])->first()) {
                        return $this->magicService->getErrorResponse('ACCOUNT_ALREADY_EXISTS', null, $request);
                    }
                    
                    $user->name = $payload['name'];
                    $user->email = $payload['email'];
                    $user->photo = $payload['picture'];
                    $user->google = $payload['sub'];
                    $user->save();
                    
                    $body = [
                        'user' => $this->magicService->getUserResponse($request, $user, [
                            'name' => $user->name,
                            'email' => $user->email,
                            'photo' => $user->photo,
                            'google' => $user->google
                        ]),
                        'message' => 'Successfully linked Guest Account and Google Account.'
                    ];
                    
                    return $this->magicService->getSuccessResponse($body, $request);
                
                case 'phone':
                    
                    $validator = Validator::make($request->json()->all(), [
                        'payload' => 'required|integer'
                    ]);
                    
                    if ($validator->fails()) return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', $validator->errors(), $request);
                    
                    if (User::where('phone', $request->json('payload'))->first()) {
                        return $this->magicService->getErrorResponse('ACCOUNT_ALREADY_EXISTS', null, $request);
                    }
                    
                    $user->name = 'User';
                    $user->phone = $request->json('payload');
                    $user->save();
                    
                    $body = [
                        'user' => $this->magicService->getUserResponse($request, $user, [
                            'name' => $user->name,
                            'phone' => $user->phone
                        ]),
                        'message' => 'Successfully linked Guest Account and Phone Number.'
                    ];
                    
                    return $this->magicService->getSuccessResponse($body, $request);

                default:
                    return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', null, $request);
            }
            
        } catch (Exception $e) {
            
            return $this->magicService->getErrorResponse('INTERNAL_SERVER_ERROR', $e->getMessage(), $request);
            
        }
    }
    
    /********************************************
    *
    * Functions to update users.
    *
    *********************************************/
    
    public function update(Request $request) {
        switch ($this->magicService->getUserType($request)) {
            case 'google':
                return $this->updateGoogle($request);
            case 'phone':
                return $this->updatePhone($request);
            default:
                return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', null, $request);
        }
    }
    
    // Google Account Update
    
    private function updateGoogle(Request $request) {
        
        $user = $this->magicService->getUser($request);
        
        if (class_basename($user) !== 'User') return $user;
        
        $payload = $this->magicService->getGooglePayload($request);
        
        $user->name = $payload['name'];
        $user->email = $payload['email'];
        
        if ($request->json('phone')) $user->phone = $request->json('phone');
        
        $user->save();
        
        $body = [
            'user' => $this->magicService->getUserResponse($request, $user),
            'message' => 'Account was updated successfully.'
        ];
        
        return $this->magicService->getSuccessResponse($body, $request);
    }
    
    // Phone Account Update
    
    private function updatePhone(Request $request) {
        
        $validator = Validator::make($request->json()->all(), [
            'name' => 'required|string',
            'phone' => 'required|numeric'
        ]);
        
        if ($validator->fails()) return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', $validator->errors(), $request);
        
        $user = $this->magicService->getUser($request);
        
        if (class_basename($user) !== 'User') return $user;
        
        $user->name = $request->json('name');
        $user->phone = $request->json('phone');
        $user->save();
        
        $body = [
            'user' => $this->magicService->getUserResponse($request, $user),
            'message' => 'Account was updated successfully.'
        ];
        
        return $this->magicService->getSuccessResponse($body, $request);
    }
    
    /********************************************
    *
    * Functions to delete users.
    *
    *********************************************/
    
    public function delete(Request $request) {
        switch ($this->magicService->getUserType($request)) {
            case 'google':
                return $this->deleteGoogle($request);
            case 'phone':
                return $this->deletePhone($request);
            case 'guest':
                return $this->deleteGuest($request);
            default:
                return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', null, $request);
        }
    }
    
    // Google Account Delete
    
    private function deleteGoogle(Request $request) {
        
        $user = $this->magicService->getUser($request);
        
        if (class_basename($user) !== 'User') return $user;
        
        $payload = $this->magicService->getGooglePayload($request);
        
        $this->out($request);
        
        $user->status = User::STATUS_INACTIVE;
        $user->delete();
        
        $body = [
            'user' => $this->magicService->getUserResponse($request, $user),
            'message' => 'Account was deleted successfully.'
        ];
        
        return $this->magicService->getSuccessResponse($body, $request);
    }
    
    // Phone Account Delete
    
    private function deletePhone(Request $request) {
        
        $user = $this->magicService->getUser($request);
        
        if (class_basename($user) !== 'User') return $user;
        
        $this->out($request);
        
        $user->status = User::STATUS_INACTIVE;
        $user->delete();
        
        $body = [
            'user' => $this->magicService->getUserResponse($request, $user),
            'message' => 'Account was deleted successfully.'
        ];
        
        return $this->magicService->getSuccessResponse($body, $request);
    }
    
    // Guest Account Delete
    
    private function deleteGuest(Request $request) {
        
        $user = $this->magicService->getUser($request);
        
        if (class_basename($user) !== 'User') return $user;
        
        $guest = $user->guest;
        
        $this->out($request);
        
        $body = [
            'user' => $this->magicService->getUserResponse($request, $user),
            'message' => 'Account was deleted successfully.'
        ];
        
        return $this->magicService->getSuccessResponse($body, $request);
    }
}