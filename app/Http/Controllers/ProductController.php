<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Product;
use App\Services\MagicService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller {

    protected $magicService;

    public function __construct(MagicService $magicService) {
        $this->magicService = $magicService;
    }

    // search products

    public function search(Request $request) {

        $validator = Validator::make($request->all(), [
            'query' => 'required|string',
            'brand_id' => 'integer',
            'min_price' => 'numeric|min:0',
            'max_price' => 'numeric|min:' . ($request->input('min_price', 0)),
            'order' => 'in:asc,dsc',
            'limit' => 'integer|min:1',
            'page' => 'integer|min:1',
        ]);

        if ($validator->fails()) return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', $validator->errors(), $request);
        
        $user = $this->magicService->getUser($request);

        if (class_basename($user) !== 'User') return $user;

        $query = Product::query();

        if ($request->has('brand_id')) {
            $brand = Brand::find($request->input('brand_id'));
            if (!$brand) return $this->magicService->getErrorResponse('ITEM_NOT_FOUND', null, $request);
            $query->where('brand_id', $request->input('brand_id'));
        }

        if ($request->has('min_price')) $query->where('purchase_price', '>=', $request->input('min_price'));

        if ($request->has('max_price')) $query->where('purchase_price', '<=', $request->input('max_price'));

        if ($request->has('name')) $query->where('name', 'like', '%' . $request->input('name') . '%');

        $query->where('status', 'ACTIVE')
            ->orderBy('purchase_price', $request->input('order', 'asc'));

        $limit = intval($request->input('limit', 25));
        $page = intval($request->input('page', 1));

        $products = $query->paginate($limit, ['*'], 'page', $page);

        $products = $this->magicService->makeHidden($products);

        $body = [
            'user' => $this->magicService->getUserResponse($request, $user),
            'items' => $products->items(),
        ];

        $body['brands'] = $products->unique('brand_id')->map(function ($product) {
            return [
                'id' => $product->brand_id,
                'name' => $product->brand->name,
                'description' => $product->brand->description,
            ];
        })->values()->all();

        $body['categories'] = $products->unique('category_id')->map(function ($product) {
            return [
                'id' => $product->category_id,
                'name' => $product->category->name,
                'description' => $product->category->description,
            ];
        })->values()->all();

        if ($products->hasMorePages()) {
            $nextPageUrl = $this->getNextPageURL($request, $products);
            $body['next_page'] = $nextPageUrl;
        }

        return $this->magicService->getSuccessResponse($body, $request);
    }

    // show product

    public function show(Request $request) {

        $validator = Validator::make($request->json()->all(), [
            'id' => 'required|integer'
        ]);

        if ($validator->fails()) return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', $validator->errors(), $request);

        $user = $this->magicService->getUser($request);

        if (class_basename($user) !== 'User') return $user;

        $product = Product::find($request->json('id'));

        if (!$product) return $this->magicService->getErrorResponse('ITEM_NOT_FOUND', null, $request);

        $body = [
            'user' => $this->magicService->getUserResponse($request, $user),
            'id' => $product->id,
            'name' => $product->name,
            'photo' => $product->photo,
            'actual_price' => $product->actual_price,
            'brand' => [
                'id' => $product->brand->id,
                'name' => $product->brand->name,
                'description' => $product->brand->description,
            ],
            'category' => [
                'id' => $product->category->id,
                'name' => $product->category->name,
                'description' => $product->category->description,
            ]
        ];

        if ($product->purchase_price) $body['purchase_price'] = $product->purchase_price;
        if ($product->description) $body['description'] = $product->description;
        if ($product->model) $body['model'] = $product->model;
        if ($product->quantity) $body['quantity'] = $product->quantity;

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
