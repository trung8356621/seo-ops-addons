{{-- Minimal plan editor hooks — server revalidates every edit --}}
@if (! empty($proposedPlan) && is_array($proposedPlan))
    <div class="seo-agent-workspace__plan-card mt-2">
        <div class="text-xs font-semibold uppercase opacity-70">Plan editor</div>
        <p class="mt-1 text-xs opacity-70">Chỉnh sửa qua Lưu / Hủy trên card đề xuất. Mọi edit resolve lại registry.</p>
    </div>
@endif
