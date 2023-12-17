<?php

namespace App\Services;

use App\Models\User;
use App\Models\Product;
use App\Services\MagicService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductService {
	
	protected $magicService;
	
	public function __construct(MagicService $magicService) {
		$this->magicService = $magicService;
	}
	
	// search items by list type
	
	public function searchList(Request $request, $type) {
		
		$validator = Validator::make($request->query()->all(), [
			'query' => 'required|string|min:1',
			'order' => 'in:asc,dsc',
			'page' => 'integer|min:0',
			'limit' => 'integer|min:1',
		]);
		
		if ($validator->fails()) return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', $validator->errors(), $request);
		
		$user = $this->magicService->getUser($request);
		
		if (class_basename($user) !== 'User') return $user;
		
		switch ($type) {
			case 'Handcart':
				$relation = 'handcarts';
				break;
			case 'Favorites':
				$relation = 'favorites';
				break;
			case 'Orders':
				$relation = 'orders';
				break;
			default:
				return $this->magicService->getErrorResponse('INTERNAL_SERVER_ERROR', null, $request);
		}
		
		$query = $user->{$relation}()
			->where(function ($q) use ($request, $type) {
				if ($type === 'Orders') {
					$q->whereHas('detail.product', function ($subQuery) use ($request) {
						$subQuery->where('name', 'like', '%' . $request->query('query') . '%');
					});
				} else {
					$q->whereHas('product', function ($subQuery) use ($request) {
						$subQuery->where('name', 'like', '%' . $request->query('query') . '%');
					});
				}
			})
			->orderBy('created_at', $request->query('order', 'asc'));
		
		$limit = intval($request->query('limit', 25));
		$page = intval($request->query('start', 1));
		
		$result = $query->paginate($limit, ['*'], 'page', $page);
		
		$body = [
			'user' => $this->magicService->getUserResponse($request, $user)
		];
		
		if ($result->hasMorePages()) {
			$nextPageUrl = $this->getNextPageURL($request, $result);
			$body['next_page'] = $nextPageUrl;
		}
		
		$body = array_merge($body, $this->setPricingDetails($request, $query, $result, $type));
		
		return $this->magicService->getSuccessResponse($body, $request);
	}
	
	// get list by list type
	
	public function getList(Request $request, $type) {
		
		$validator = Validator::make($request->all(), [
			'order' => 'in:asc,dsc',
			'page' => 'integer|min:1',
			'limit' => 'integer|min:1',
		]);
		
		if ($validator->fails()) return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', $validator->errors(), $request);
		
		$user = $this->magicService->getUser($request);
		
		if (class_basename($user) !== 'User') return $user;
		
		
		
		switch ($type) {
			case 'Handcart':
				$relation = 'handcarts';
				break;
			case 'Favorites':
				$relation = 'favorites';
				break;
			case 'Orders':
				$relation = 'orders';
				break;
			default:
				return $this->magicService->getErrorResponse('INTERNAL_SERVER_ERROR', null, $request);
		}
		
		$query = $user->{$relation}()
			->orderBy('created_at', $request->query('order', 'asc'));
	
		$limit = intval($request->query('limit', 25));
		$page = intval($request->query('page', 1));
		
		$result = $query->paginate($limit, ['*'], 'page', $page);
		
		$body = [
			'user' => $this->magicService->getUserResponse($request, $user)
		];
		
		if ($result->hasMorePages()) {
			$nextPageUrl = $this->getNextPageURL($request, $result);
			$body['next_page'] = $nextPageUrl;
		}
		
		$body = array_merge($body, $this->setPricingDetails($request, $query, $result, $type));
		
		return $this->magicService->getSuccessResponse($body, $request);
	}
	
	// add product to list by list type
	
	public function addToList(Request $request, $type) {
		
		$validator = Validator::make($request->json()->all(), [
			'id' => 'required|integer',
			'quantity' => 'integer|min:1',
		]);
		
		if ($validator->fails()) return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', $validator->errors(), $request);
		
		$product = Product::find($request->json('id'));
		
		if (!$product) return $this->magicService->getErrorResponse('ITEM_NOT_FOUND', null, $request);
		
		$user = $this->magicService->getUser($request);
		
		if (class_basename($user) !== 'User') return $user;
		
		switch ($type) {
			case 'Handcart':
				$relation = 'handcarts';
				break;
			case 'Favorites':
				$relation = 'favorites';
				break;
			default:
				return $this->magicService->getErrorResponse('INTERNAL_SERVER_ERROR', null, $request);
		}

		$item = $user->{$relation}()->where('product_id', $request->json('id'))->first();
		
		if ($item) {
			$item->quantity += $request->json('quantity', 1);
			$item->save();
		} else {
			$user->{$relation}()->create([
				'product_id' => $request->json('id'),
				'quantity' => $request->json('quantity', 1),
			]);
		}
		
		$body = [
			'user' => $this->magicService->getUserResponse($request, $user),
			'message' => "Item added to your $type successfully."
		];
		
		return $this->magicService->getSuccessResponse($body, $request);
	}
	
	// update product quantity by list type
	
	public function updateInList(Request $request, $type) {
		
		$validator = Validator::make($request->json()->all(), [
			'id' => 'required|integer',
			'quantity' => 'required|integer|min:0',
		]);
		
		if ($validator->fails()) return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', $validator->errors(), $request);
		
		$product = Product::find($request->json('id'));
		
		if (!$product) return $this->magicService->getErrorResponse('ITEM_NOT_FOUND', null, $request);
		
		$user = $this->magicService->getUser($request);
		
		if (class_basename($user) !== 'User') return $user;
		
		if ($request->json('quantity') == 0) return $this->removeFromList($request, $user, $type);
		
		switch ($type) {
			case 'Handcart':
				$relation = 'handcarts';
				break;
			case 'Favorites':
				$relation = 'favorites';
				break;
			default:
				return $this->magicService->getErrorResponse('INTERNAL_SERVER_ERROR', null, $request);
		}

		$item = $user->{$relation}()
			->where('product_id', $request->json('id'))
			->first();
			
		if (!$item) return $this->magicService->getErrorResponse('ITEM_NOT_FOUND', null, $request);
		
		$item->quantity = $request->json('quantity');
		$item->save();
		$body = [
			'user' => $this->magicService->getUserResponse($request, $user),
			'message' => "Item quantity updated in your $type successfully."
		];
		
		return $this->magicService->getSuccessResponse($body, $request);
	}
	
	// remove product from list by list type
	
	public function removeFromList(Request $request, $type) {
		
		$validator = Validator::make($request->json()->all(), [
			'id' => 'required|integer',
		]);
		
		if ($validator->fails()) return $this->magicService->getErrorResponse('MISSING_OR_INVALID_FIELDS', $validator->errors(), $request);
		
		$product = Product::find($request->json('id'));
		
		if (!$product) return $this->magicService->getErrorResponse('ITEM_NOT_FOUND', null, $request);
		
		$user = $this->magicService->getUser($request);
		
		if (class_basename($user) !== 'User') return $user;
		
		switch ($type) {
			case 'Handcart':
				$relation = 'handcarts';
				break;
			case 'Favorites':
				$relation = 'favorites';
				break;
			default:
				return $this->magicService->getErrorResponse('INTERNAL_SERVER_ERROR', null, $request);
		}

		$item = $user->{$relation}()
			->where('product_id', $request->json('id'))
			->first();
		
		if (!$item) return $this->magicService->getErrorResponse('ITEM_NOT_FOUND', null, $request);
		
		$item->delete();
		$body = [
			'user' => $this->magicService->getUserResponse($request, $user),
			'message' => "Item removed from your $type successfully."
		];
		
		return $this->magicService->getSuccessResponse($body, $request);
	}
	
	// set pricing details in response
	
	private function setPricingDetails(Request $request, $query, $result, $type, $calculate = true) {
		
		$body = ['items' => $this->getItems($result, $type)];
		
		$products = $result->map(function ($item) use ($type) {
			$product = $type === 'Orders' ? $item->detail->product : $item->product;
			return $product;
		});
		
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
		
		if ($type === 'Orders') {
			$totalItems = $query->with('detail')->get()->sum(function ($order) {
				return $order->detail->sum('quantity');
			});
			$totalRetailPrice = $query->with('detail')->get()->sum(function ($order) {
				return $order->detail->sum('retail_price');
			});
			$totalCurrentPrice = $query->with('detail')->get()->sum(function ($order) {
				return $order->detail->sum('current_price');
			});
		} else {
			$totalItems = intval($query->sum('quantity'));
			$totalRetailPrice = $query->with('product')->get()->sum('product.retail_price');
			$totalCurrentPrice = $query->with('product')->get()->sum('product.current_price');
		}
		
		if ($totalItems > 0) {
			
			$body['details'] = [
				'total_items' => $totalItems,
				'retail_price' => $totalRetailPrice,
				'current_price' => $totalCurrentPrice
			];
			
			if ($calculate) {
				$totalPrice = number_format($totalPurchasePrice, 2);
				$totalSavings = number_format($totalActualPrice - $totalPurchasePrice, 2);
				
				$body['message'] = "Total $totalItems items worth ₹$totalPrice in your $type";
				
				$body['message'] .= $totalSavings > 0 ? (
					", you " . $type === 'Orders' ?
					"have saved ₹$totalSavings so far." :
					"will save ₹$totalSavings if you order now."
				) : ".";
				
			} else {
				$body['message'] = "Total $totalItems products found in your $type for this query.";
			}
			
		} else {
			if ($calculate) {
				$body['message'] = "There are currently no items in your $type.";
			} else {
				$body['message'] = "No products found in your $type for this query.";
			}
		}
		
		return $this->setNextPageURL($request, $result, $body);
	}
	
	// set next page url in response
	
	private function setNextPageURL(Request $request, $result, $body) {

		$nextPageUrl = $result->nextPageUrl();
		
		if ($nextPageUrl !== null) {
			$body['next_page'] = $nextPageUrl;
		}
		
		return $body;
	}
	
	private function getItems($result, $type) {
		return $result->map(function ($object) use ($type) {
			$item = $type === 'Orders' ? $object->detail : $object;
			return [
				'id' => $item->product->id,
				'brand_id' => $item->product->brand_id,
				'category_id' => $item->product->category_id,
				'name' => $item->product->name,
				'photo' => $item->product->photo,
				'description' => $item->product->photo,
				'model' => $item->product->model,
				'quantity' => $item->quantity,
				'retail_price' => intval($item->product->retail_price),
				'current_price' => intval($item->product->current_price),
				'total_price' => $item->quantity * $item->product->current_price
			];
		})->map(function ($item) {
			return array_filter($item, function ($value) {
				return !empty($value);
			});
		})->all();
	}

}