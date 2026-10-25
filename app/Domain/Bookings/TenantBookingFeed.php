<?php

namespace App\Domain\Bookings;

use App\Models\Booking;
use Illuminate\Contracts\Pagination\CursorPaginator;

final class TenantBookingFeed
{
    /**
     * @return CursorPaginator<int, Booking>
     */
    public function latest(int $tenantId, int $perPage = 15, ?string $cursor = null): CursorPaginator
    {
        return Booking::query()
            ->where('tenant_id', $tenantId)
            ->with(['service:id,name', 'customer:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage, ['id', 'tenant_id', 'service_id', 'customer_id', 'starts_at', 'status', 'created_at'], 'cursor', $cursor);
    }
}
