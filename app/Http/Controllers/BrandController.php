<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Services\MagicService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;

class BrandController extends Controller {
    
    protected $magicService;
    
    public function __construct(MagicService $magicService) {
        $this->magicService = $magicService;
    }
    
    public function list(Request $request) {
        
        $validator = Validator::make($request->all(), [
            'order' => 'in:asc,dsc',
            'limit' => 'integer|min:1',
            'page' => 'integer|min:1',
        ]);

        if ($validator->fails()) return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', $validator->errors(), $request);
        
        $user = $this->magicService->getUser($request);
        
        if (class_basename($user) !== 'User') return $user;

        $limit = intval($request->input('limit', 100));
        $page = intval($request->input('page', 1));

        $order = $request->input('order', 'asc');
        $brands = Brand::orderBy('name', $order)->paginate($limit, ['*'], 'page', $page);

        $body = [
            'user' => $this->magicService->getUserResponse($request, $user),
            'brands' => $brands->items(),
        ];

        if ($brands->hasMorePages()) {
            $nextPageUrl = $this->getNextPageURL($request, $brands);
            $body['next_page'] = $nextPageUrl;
        }

        return $this->magicService->getSuccessResponse($body, $request);
    }

    private function getNextPageURL(Request $request, $paginator) {
        $nextPageUrl = null;
        if ($paginator->hasMorePages()) {
            $nextPageUrl = $paginator->nextPageUrl();
        }

        return $nextPageUrl;
    }
}