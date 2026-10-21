<?php

namespace App\Modules\Commerce\Http;

use App\Core\Activity\Activity;
use App\Core\Http\Controller;
use App\Modules\Commerce\CommerceText;
use App\Modules\Commerce\Coupons;
use App\Modules\Commerce\Orders;
use Illuminate\Http\Request;

/**
 * Panel: siparişler ve kuponlar (`module:commerce`).
 *
 *   GET    /admin/commerce/orders              ?status=&q= liste + durum sayıları   (km_view_orders)
 *   GET    /admin/commerce/orders/{id}         ayrıntı                               (km_view_orders)
 *   POST   /admin/commerce/orders/{id}/status  { status } akışa uygun geçiş           (km_manage_orders)
 *   GET    /admin/commerce/coupons             liste                                 (km_manage_coupons)
 *   POST   /admin/commerce/coupons             ekle; PUT …/{id} güncelle; DELETE …/{id}
 */
final class OrdersController extends Controller
{
    public function index(Request $request): array
    {
        return (new Orders)->list((string) $request->query('status', ''), (string) $request->query('q', ''));
    }

    public function show(int $id): array
    {
        return (new Orders)->view($id);
    }

    public function status(Request $request, int $id): array
    {
        $status = (string) ($this->input($request)['status'] ?? '');
        $order = (new Orders)->setStatus($id, $status);
        Activity::subject('order', $id, $order['number']);
        Activity::with(['number' => $order['number'], 'status' => CommerceText::get('status.'.$status, 'tr')]);

        return $order;
    }

    public function coupons(): array
    {
        return ['items' => Coupons::all()];
    }

    public function saveCoupon(Request $request, ?int $id = null): array
    {
        $coupon = Coupons::save($id, $this->input($request));
        Activity::subject('coupon', $coupon['id'], $coupon['code']);
        Activity::with(['code' => $coupon['code']]);

        return $coupon;
    }

    public function deleteCoupon(int $id): array
    {
        $code = (string) (collect(Coupons::all())->firstWhere('id', $id)['code'] ?? '');
        Coupons::delete($id);
        Activity::subject('coupon', $id, $code);
        Activity::with(['code' => $code]);

        return ['deleted' => true];
    }
}
