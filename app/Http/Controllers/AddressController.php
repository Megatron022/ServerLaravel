<?php

namespace App\Http\Controllers;

use App\Models\Address;
use App\Models\Postcode;
use App\Models\User;
use App\Services\MagicService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AddressController extends Controller {

    protected $magicService;

    public function __construct(MagicService $magicService) {
        $this->magicService = $magicService;
    }

    public function postcode(Request $request) {

        $validator = Validator::make($request->json()->all(), [
            'postcode' => 'required|string'
        ]);

        if ($validator->fails()) return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', $validator->errors(), $request);

        if (!Postcode::where('id', $request->postcode_id)->exists()) return $this->magicService->getErrorResponse('ITEM_NOT_FOUND', null, $request);

        $user = $this->magicService->getUser($request);

        if (class_basename($user) !== 'User') return $user;

        $postcode = Postcode::where('code', $request->input('postcode'))->first();

        if (!$postcode) return $this->magicService->getErrorResponse('ITEM_NOT_FOUND', null, $request);

        $body = [
            'id' => $postcode->id,
            'code' => $postcode->code,
            'city' => $postcode->city,
            'district' => $postcode->district,
            'state' => $postcode->state,
            'country' => $postcode->country
        ];

        return $this->magicService->getSuccessResponse($body, $request);
    }

    public function search(Request $request) {

        $validator = Validator::make($request->all(), [
            'query' => 'required|string',
            'order' => 'in:asc,dsc',
            'page' => 'integer|min:1',
            'limit' => 'integer|min:1',
        ]);

        if ($validator->fails()) return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', $validator->errors(), $request);

        $user = $this->magicService->getUser($request);

        if (class_basename($user) !== 'User') return $user;

        $query = $request->input('query');

        $addresses = $user->addresses()
            ->with('postcode')
            ->where(function ($queryBuilder) use ($query) {
                $queryBuilder->where('name', 'like', "%$query%")
                ->orWhere('line_1', 'like', "%$query%")
                ->orWhere('phone', 'like', "%$query%");
            })
            ->orderBy('name', $request->input('order', 'asc'))
            ->paginate($request->input('limit', 25));

        $addresses = $this->magicService->makeHidden($addresses);

        $body = [
            'user' => $this->magicService->getUserResponse($request, $user),
            'addresses' => $addresses->getCollection()
        ];

        if ($addresses->nextPageUrl()) {
            $body['next_page_url'] = $addresses->nextPageUrl();
        }

        return $this->magicService->getSuccessResponse($body, $request);
    }

    public function list(Request $request) {

        $validator = Validator::make($request->all(), [
            'order' => 'in:asc,dsc',
            'page' => 'integer|min:1',
            'limit' => 'integer|min:1',
        ]);

        if ($validator->fails()) return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', $validator->errors(), $request);

        $user = $this->magicService->getUser($request);

        if (class_basename($user) !== 'User') return $user;

        $page = $request->input('page', 1);
        $limit = $request->input('limit', 25);

        $addresses = $user->addresses()
            ->with('postcode')
            ->orderBy('name', $request->input('order', 'asc'))
            ->paginate($limit, ['*'], 'page', $page);

        $addresses = $this->magicService->makeHidden($addresses, ['postcode_id']);

        $body = [
            'user' => $this->magicService->getUserResponse($request, $user),
            'addresses' => $addresses->getCollection()
        ];

        if ($addresses->nextPageUrl()) {
            $body['next_page_url'] = $addresses->nextPageUrl();
        }

        return $this->magicService->getSuccessResponse($body, $request);
    }

    public function add(Request $request) {

        $validator = Validator::make($request->json()->all(), [
            'postcode_id' => 'required|integer',
            'name' => 'required',
            'care_of' => 'required',
            'phone' => 'required',
            'line_1' => 'required',
            'line_2' => 'required',
            'type' => 'in:HOME,OFFICE,OTHER'
        ]);

        if ($validator->fails()) return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', $validator->errors(), $request);

        $user = $this->magicService->getUser($request);

        if (class_basename($user) !== 'User') return $user;

        if (!Postcode::where('id', $request->postcode_id)->exists()) return $this->magicService->getErrorResponse('ITEM_NOT_FOUND', null, $request);

        $address = new Address($request->json()->all());

        if ($user->addresses->isEmpty()) {
            $address->default = true;
        }

        $user->addresses()->save($address);

        $body = [
            'user' => $this->magicService->getUserResponse($request, $user),
            'id' => $address->id,
            'message' => 'Address created successfully.'
        ];

        return $this->magicService->getSuccessResponse($body, $request);
    }

    public function show(Request $request) {

        $validator = Validator::make($request->json()->all(), [
            'id' => 'required|integer'
        ]);

        if ($validator->fails()) return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', $validator->errors(), $request);

        $user = $this->magicService->getUser($request);

        if (class_basename($user) !== 'User') return $user;

        $address = Address::find($request->input('id'))->with('postcode')->makeHidden([
            'user_id',
            'status',
            'created_at',
            'updated_at',
            'deleted_at'
        ]);

        if (!$address) return $this->magicService->getErrorResponse('ITEM_NOT_FOUND', null, $request);

        $addresses = $this->magicService->makeHidden($addresses);

        $body = [
            'user' => $this->magicService->getUserResponse($request, $user),
            'address' => $address
        ];

        return $this->magicService->getSuccessResponse($body, $request);
    }

    public function update(Request $request) {

        $validator = Validator::make($request->json()->all(), [
            'id' => 'required|integer',
            'postcode_id' => 'integer',
            'default' => 'boolean',
            'type' => 'in:HOME,OFFICE,OTHER'
        ]);

        if ($validator->fails()) return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', $validator->errors(), $request);

        $user = $this->magicService->getUser($request);

        if (class_basename($user) !== 'User') return $user;

        if (!Postcode::where('id', $request->postcode_id)->exists()) return $this->magicService->getErrorResponse('ITEM_NOT_FOUND', null, $request);

        $address = Address::find($request->input('id'));

        if (!$address) return $this->magicService->getErrorResponse('ITEM_NOT_FOUND', null, $request);

        if ($request->has('default') && $request->json('default')) {
            $user->addresses()->where('id', '<>', $address->id)->update(['default' => false]);
        }

        $address->update($request->json()->all());

        $body = [
            'user' => $this->magicService->getUserResponse($request, $user),
            'message' => 'Address updated successfully.'
        ];

        return $this->magicService->getSuccessResponse($body, $request);
    }

    public function delete(Request $request) {

        $validator = Validator::make($request->json()->all(), [
            'id' => 'required|integer',
        ]);

        if ($validator->fails()) return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', $validator->errors(), $request);

        $user = $this->magicService->getUser($request);

        if (class_basename($user) !== 'User') return $user;

        $address = Address::find($request->input('id'));

        if (!$address) return $this->magicService->getErrorResponse('ITEM_NOT_FOUND', null, $request);

        $address->delete();

        $body = [
            'user' => $this->magicService->getUserResponse($request, $user),
            'message' => 'Address removed successfully.'
        ];

        return $this->magicService->getSuccessResponse($body, $request);
    }
}
