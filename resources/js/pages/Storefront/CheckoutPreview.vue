<script setup lang="ts">
// Local-only preview of the checkout frame (routes/local.php). The real
// checkout steps arrive with the cart and payments (CJ-1, blocked on ADR-0011).
import { Head } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';
import EmptyState from '@/shared/ui/EmptyState.vue';
import SurfaceCard from '@/shared/ui/SurfaceCard.vue';
import CheckoutLayout from '@/storefront/layouts/CheckoutLayout.vue';

defineOptions({ layout: CheckoutLayout });

const { t } = useI18n();
</script>

<template>
    <Head :title="t('storefront.checkout.title')" />

    <h1 class="text-3xl font-semibold tracking-tight">{{ t('storefront.checkout.title') }}</h1>

    <SurfaceCard class="mt-6">
        <EmptyState :title="t('storefront.checkout.preview_title')" :description="t('storefront.checkout.preview_body')" />
    </SurfaceCard>
</template>
