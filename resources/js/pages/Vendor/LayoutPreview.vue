<script setup lang="ts">
// Local-only preview of the vendor dashboard frame (routes/local.php). Real
// vendor pages need the vendor-context route group (ADR-0011, M6).
import { Head } from '@inertiajs/vue3';
import { useI18n } from '@/i18n';
import EmptyState from '@/shared/ui/EmptyState.vue';
import SurfaceCard from '@/shared/ui/SurfaceCard.vue';
import VendorLayout from '@/vendor/layouts/VendorLayout.vue';

defineOptions({ layout: VendorLayout });

const { t } = useI18n();
</script>

<template>
    <Head :title="t('vendor.dashboard.title')" />

    <h1 class="text-2xl font-semibold tracking-tight">{{ t('vendor.dashboard.title') }}</h1>

    <SurfaceCard class="mt-6">
        <EmptyState :title="t('vendor.dashboard.empty_title')" :description="t('vendor.dashboard.empty_body')" />
    </SurfaceCard>
</template>
