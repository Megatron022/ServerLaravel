<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\MagicService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;

class OTPController extends Controller {

    protected $magicService;

    public function __construct(MagicService $magicService) {
        $this->magicService = $magicService;
    }

    public function verify(Request $request) {

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

        // TODO: implement logic

        // otp table -> id, country, number, otp, created at, updated at, verified at, deleted at
        // database check if number is linked to any account previously

        $body = [
            'user' => $this->magicService->getUserResponse($request, $user),
            'message' => 'Successfully sent OTP.',
            'expires' => Carbon::now()->addSeconds(120)->timestamp * 1000
        ];

        return $this->magicService->getSuccessResponse($body, $request);
    }
}
