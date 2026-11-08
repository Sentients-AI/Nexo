<script setup lang="ts">
// Local-only component gallery (routes/local.php). Every kit component in
// its main states, switchable between en, ms and ar; switching re-runs
// activateLocale(), so <html lang dir> flips exactly as it does on the
// storefront. Sample copy lives here (dev-only); the components' own labels
// come from the real catalogues.
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { activateLocale, LOCALES, useI18n, type Locale } from '@/i18n';
import AppButton from '@/shared/ui/AppButton.vue';
import CheckboxInput from '@/shared/ui/CheckboxInput.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import FormField from '@/shared/ui/FormField.vue';
import LoadingSkeleton from '@/shared/ui/LoadingSkeleton.vue';
import ModalDialog from '@/shared/ui/ModalDialog.vue';
import MoneyPrice from '@/shared/ui/MoneyPrice.vue';
import PageNav from '@/shared/ui/PageNav.vue';
import SelectInput from '@/shared/ui/SelectInput.vue';
import StatusBadge from '@/shared/ui/StatusBadge.vue';
import SurfaceCard from '@/shared/ui/SurfaceCard.vue';
import TextInput from '@/shared/ui/TextInput.vue';
import TextLink from '@/shared/ui/TextLink.vue';
import ToastRegion from '@/shared/ui/ToastRegion.vue';
import { push } from '@/shared/ui/toasts';

const { locale } = useI18n();

const COPY = {
    en: {
        save: 'Save changes', cancel: 'Cancel', remove: 'Remove', paying: 'Paying',
        email: 'Email', emailHint: 'We send your receipt here.', emailError: 'Enter a valid email address.',
        state: 'State', choose: 'Choose a state', giftWrap: 'Add gift wrap', giftHint: 'RM 5.00 per order',
        paid: 'Paid', pending: 'Awaiting payment', shipped: 'Shipped', cancelled: 'Cancelled', review: 'In review',
        cardTitle: 'Order summary', cardBody: 'Two items from one vendor.', viewOrder: 'View order',
        emptyTitle: 'Your cart is empty', emptyBody: 'Items you add will appear here.', browse: 'Browse products',
        dialogTitle: 'Remove this item?', dialogBody: 'It will be removed from your cart.', open: 'Open dialog',
        toastOk: 'Added to cart', toastErr: 'Payment failed. Try another card.', notify: 'Show toast', notifyErr: 'Show error',
        external: 'Help centre',
    },
    ms: {
        save: 'Simpan perubahan', cancel: 'Batal', remove: 'Buang', paying: 'Membayar',
        email: 'E-mel', emailHint: 'Resit anda dihantar ke sini.', emailError: 'Masukkan alamat e-mel yang sah.',
        state: 'Negeri', choose: 'Pilih negeri', giftWrap: 'Tambah pembalut hadiah', giftHint: 'RM 5.00 setiap pesanan',
        paid: 'Dibayar', pending: 'Menunggu bayaran', shipped: 'Dihantar', cancelled: 'Dibatalkan', review: 'Dalam semakan',
        cardTitle: 'Ringkasan pesanan', cardBody: 'Dua item daripada satu penjual.', viewOrder: 'Lihat pesanan',
        emptyTitle: 'Troli anda kosong', emptyBody: 'Item yang anda tambah akan dipaparkan di sini.', browse: 'Lihat produk',
        dialogTitle: 'Buang item ini?', dialogBody: 'Item ini akan dibuang daripada troli anda.', open: 'Buka dialog',
        toastOk: 'Ditambah ke troli', toastErr: 'Bayaran gagal. Cuba kad lain.', notify: 'Papar notis', notifyErr: 'Papar ralat',
        external: 'Pusat bantuan',
    },
    ar: {
        save: 'حفظ التغييرات', cancel: 'إلغاء', remove: 'إزالة', paying: 'جارٍ الدفع',
        email: 'البريد الإلكتروني', emailHint: 'نرسل إيصالك إلى هنا.', emailError: 'أدخل عنوان بريد إلكتروني صالحًا.',
        state: 'الولاية', choose: 'اختر الولاية', giftWrap: 'إضافة تغليف هدية', giftHint: 'RM 5.00 لكل طلب',
        paid: 'مدفوع', pending: 'بانتظار الدفع', shipped: 'تم الشحن', cancelled: 'ملغى', review: 'قيد المراجعة',
        cardTitle: 'ملخص الطلب', cardBody: 'منتجان من بائع واحد.', viewOrder: 'عرض الطلب',
        emptyTitle: 'سلتك فارغة', emptyBody: 'ستظهر هنا المنتجات التي تضيفها.', browse: 'تصفح المنتجات',
        dialogTitle: 'إزالة هذا المنتج؟', dialogBody: 'ستتم إزالته من سلتك.', open: 'فتح النافذة',
        toastOk: 'تمت الإضافة إلى السلة', toastErr: 'فشل الدفع. جرّب بطاقة أخرى.', notify: 'عرض إشعار', notifyErr: 'عرض خطأ',
        external: 'مركز المساعدة',
    },
} satisfies Record<Locale, Record<string, string>>;

const copy = computed(() => COPY[locale.value]);
const NAMES: Record<Locale, string> = { en: 'English', ms: 'Bahasa Melayu', ar: 'العربية' };

const email = ref('aisyah@');
const state = ref('');
const giftWrap = ref(true);
const dialogOpen = ref(false);
const states = [
    { value: 'jhr', label: 'Johor' },
    { value: 'kul', label: 'Kuala Lumpur' },
    { value: 'sbh', label: 'Sabah' },
];
</script>

<template>
    <Head title="Components" />

    <main class="mx-auto max-w-content px-gutter py-section">
        <header class="flex flex-wrap items-end justify-between gap-6 border-b border-border pb-8">
            <div>
                <p class="text-xs font-medium tracking-wide text-fg-muted uppercase" lang="en">Local only · APP_ENV=local</p>
                <h1 class="mt-2 text-4xl font-semibold tracking-tight" lang="en">Components</h1>
            </div>
            <div role="group" aria-label="Locale" class="flex gap-2" lang="en">
                <AppButton
                    v-for="code in LOCALES"
                    :key="code"
                    size="sm"
                    :variant="code === locale ? 'primary' : 'secondary'"
                    :aria-pressed="code === locale"
                    :data-test="`locale-${code}`"
                    @click="activateLocale(code)"
                    ><span :lang="code">{{ NAMES[code] }}</span></AppButton
                >
            </div>
        </header>

        <section class="mt-12" data-test="section-button">
            <h2 class="text-lg font-semibold">AppButton · TextLink</h2>
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <AppButton>{{ copy.save }}</AppButton>
                <AppButton variant="secondary">{{ copy.cancel }}</AppButton>
                <AppButton variant="ghost">{{ copy.cancel }}</AppButton>
                <AppButton variant="danger">{{ copy.remove }}</AppButton>
                <AppButton loading>{{ copy.paying }}</AppButton>
                <AppButton disabled>{{ copy.save }}</AppButton>
                <TextLink href="/">{{ copy.viewOrder }}</TextLink>
                <TextLink href="https://example.com" external>{{ copy.external }}</TextLink>
                <TextLink href="/" appearance="secondary">{{ copy.browse }}</TextLink>
            </div>
        </section>

        <section class="mt-12 grid gap-6 md:grid-cols-2" data-test="section-form">
            <h2 class="text-lg font-semibold md:col-span-2">FormField · TextInput · SelectInput · CheckboxInput</h2>
            <FormField :label="copy.email" :hint="copy.emailHint" :error="copy.emailError" required>
                <TextInput v-model="email" type="email" autocomplete="email" />
            </FormField>
            <FormField :label="copy.state">
                <SelectInput v-model="state" :options="states" :placeholder="copy.choose" />
            </FormField>
            <CheckboxInput v-model="giftWrap" :label="copy.giftWrap" :hint="copy.giftHint" />
        </section>

        <section class="mt-12" data-test="section-badge">
            <h2 class="text-lg font-semibold">StatusBadge · MoneyPrice</h2>
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <StatusBadge tone="success">{{ copy.paid }}</StatusBadge>
                <StatusBadge tone="warning">{{ copy.pending }}</StatusBadge>
                <StatusBadge tone="info">{{ copy.shipped }}</StatusBadge>
                <StatusBadge tone="danger">{{ copy.cancelled }}</StatusBadge>
                <StatusBadge>{{ copy.review }}</StatusBadge>
            </div>
            <div class="mt-4 flex flex-wrap items-baseline gap-8">
                <MoneyPrice :amount="{ amount: 241850, currency: 'MYR' }" size="lg" />
                <MoneyPrice :amount="{ amount: 9900, currency: 'MYR' }" :compare-at="{ amount: 12900, currency: 'MYR' }" />
            </div>
        </section>

        <section class="mt-12 grid gap-6 md:grid-cols-2" data-test="section-card">
            <h2 class="text-lg font-semibold md:col-span-2">SurfaceCard · LoadingSkeleton · EmptyState</h2>
            <SurfaceCard :title="copy.cardTitle" :level="3">
                <p class="text-fg-muted">{{ copy.cardBody }}</p>
                <template #footer><TextLink href="/">{{ copy.viewOrder }}</TextLink></template>
            </SurfaceCard>
            <SurfaceCard aria-busy="true">
                <div class="flex items-center gap-4">
                    <LoadingSkeleton shape="circle" />
                    <div class="flex-1 space-y-2">
                        <LoadingSkeleton width="60%" />
                        <LoadingSkeleton width="40%" />
                    </div>
                </div>
                <LoadingSkeleton shape="block" class="mt-4" />
            </SurfaceCard>
            <SurfaceCard class="md:col-span-2" :padded="false">
                <EmptyState :title="copy.emptyTitle" :description="copy.emptyBody" :level="3">
                    <template #icon>
                        <svg class="size-10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M3 4h2l2.4 11.2a1 1 0 0 0 1 .8h9.2a1 1 0 0 0 1-.8L21 8H6" />
                        </svg>
                    </template>
                    <template #action><TextLink href="/" appearance="primary">{{ copy.browse }}</TextLink></template>
                </EmptyState>
            </SurfaceCard>
        </section>

        <section class="mt-12" data-test="section-overlay">
            <h2 class="text-lg font-semibold">ModalDialog · ToastRegion</h2>
            <div class="mt-4 flex flex-wrap gap-3">
                <AppButton variant="secondary" data-test="open-dialog" @click="dialogOpen = true">{{ copy.open }}</AppButton>
                <AppButton variant="secondary" @click="push('success', copy.toastOk)">{{ copy.notify }}</AppButton>
                <AppButton variant="secondary" @click="push('error', copy.toastErr)">{{ copy.notifyErr }}</AppButton>
            </div>
            <ModalDialog v-model:open="dialogOpen" :title="copy.dialogTitle" :description="copy.dialogBody">
                <template #actions>
                    <AppButton variant="secondary" @click="dialogOpen = false">{{ copy.cancel }}</AppButton>
                    <AppButton variant="danger" @click="dialogOpen = false">{{ copy.remove }}</AppButton>
                </template>
            </ModalDialog>
        </section>

        <section class="mt-12" data-test="section-pagination">
            <h2 class="text-lg font-semibold">PageNav</h2>
            <div class="mt-4 space-y-4">
                <PageNav :page="5" :last-page="12" :href="(p) => `/dev/components?page=${p}`" />
                <PageNav :page="1" :last-page="4" :href="(p) => `/dev/components?page=${p}`" label="Short list" />
            </div>
        </section>

        <ToastRegion />
    </main>
</template>
