<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Users } from 'lucide-vue-next';

import { useI18n } from 'vue-i18n';
const props = defineProps({
    organization: Object,
    user: Object,
    aiSettings: {
        type: Object,
        default: () => ({
            minimax_configured: false,
            minimax_base_url: 'https://api.minimax.io/v1',
            minimax_model: 'MiniMax-M2.7',
        }),
    },
});


const { t } = useI18n();
const activeTab = ref('general');

// General settings form
const generalForm = useForm({
    name: props.organization.name || '',
    email: props.organization.email || '',
    phone: props.organization.phone || '',
    address: props.organization.address || '',
    city: props.organization.city || '',
    state: props.organization.state || '',
    zip: props.organization.zip || '',
    country: props.organization.country || '',
});

const submitGeneral = () => {
    generalForm.patch(route('settings.organization.update.general'), {
        preserveScroll: true,
    });
};

// Regional settings form
const regionalForm = useForm({
    currency: props.organization.currency || 'USD',
    timezone: props.organization.timezone || 'UTC',
    date_format: props.organization.date_format || 'Y-m-d',
    time_format: props.organization.time_format || 'H:i',
});

const submitRegional = () => {
    regionalForm.patch(route('settings.organization.update.regional'), {
        preserveScroll: true,
    });
};

// AI settings form
const aiForm = useForm({
    minimax_api_key: '',
    minimax_base_url: props.aiSettings.minimax_base_url || 'https://api.minimax.io/v1',
    minimax_model: props.aiSettings.minimax_model || 'MiniMax-M2.7',
});

const submitAi = () => {
    aiForm.patch(route('settings.organization.update.ai'), {
        preserveScroll: true,
        onSuccess: () => {
            aiForm.minimax_api_key = '';
        },
    });
};

const isAdmin = props.user.is_admin;

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring disabled:cursor-not-allowed disabled:opacity-60';
const fieldArea = 'w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';

const tabs = [
    { key: 'general', label: 'General Information' },
    { key: 'regional', label: 'Regional Settings' },
    { key: 'ai', label: 'AI 设置' },
];
</script>

<template>
    <Head :title="t('settings.organization.title')" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('settings.account.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('settings.account.index')" class="text-text-tertiary hover:text-text-primary">Settings</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">{{ t('settings.organization.title') }}</span>
            </div>
        </template>

        <PageHeader :title="t('settings.organization.title')" description="Manage your organization's profile and regional preferences." />

        <!-- Tabs -->
        <div class="mt-6 border-b border-border-subtle">
            <nav class="-mb-px flex gap-8">
                <button
                    @click="activeTab = 'general'"
                    :class="[
                        'border-b-2 px-1 py-3 text-sm font-medium transition-colors',
                        activeTab === 'general'
                            ? 'border-brand text-brand'
                            : 'border-transparent text-text-tertiary hover:border-border-strong hover:text-text-secondary'
                    ]"
                >
                    General Information
                </button>
                <button
                    @click="activeTab = 'regional'"
                    :class="[
                        'border-b-2 px-1 py-3 text-sm font-medium transition-colors',
                        activeTab === 'regional'
                            ? 'border-brand text-brand'
                            : 'border-transparent text-text-tertiary hover:border-border-strong hover:text-text-secondary'
                    ]"
                >
                    Regional Settings
                </button>
                <button
                    @click="activeTab = 'ai'"
                    :class="[
                        'border-b-2 px-1 py-3 text-sm font-medium transition-colors',
                        activeTab === 'ai'
                            ? 'border-brand text-brand'
                            : 'border-transparent text-text-tertiary hover:border-border-strong hover:text-text-secondary'
                    ]"
                >
                    AI 设置
                </button>
                <button
                    v-if="isAdmin"
                    @click="activeTab = 'users'"
                    :class="[
                        'border-b-2 px-1 py-3 text-sm font-medium transition-colors',
                        activeTab === 'users'
                            ? 'border-brand text-brand'
                            : 'border-transparent text-text-tertiary hover:border-border-strong hover:text-text-secondary'
                    ]"
                >
                    User Management
                </button>
            </nav>
        </div>

        <!-- General Information Tab -->
        <div v-show="activeTab === 'general'" class="mt-6">
            <Card :padded="false">
                <form @submit.prevent="submitGeneral">
                    <div class="px-5 pt-5">
                        <h3 class="text-sm font-semibold text-text-primary">Organization Information</h3>
                    </div>
                    <div class="p-5">
                        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                            <div>
                                <label for="name" :class="fieldLabel">Organization Name</label>
                                <input
                                    id="name"
                                    v-model="generalForm.name"
                                    type="text"
                                    :class="fieldInput"
                                    :disabled="!isAdmin"
                                />
                                <p v-if="generalForm.errors.name" :class="fieldError">{{ generalForm.errors.name }}</p>
                            </div>
                            <div>
                                <label for="email" :class="fieldLabel">Email</label>
                                <input
                                    id="email"
                                    v-model="generalForm.email"
                                    type="email"
                                    :class="fieldInput"
                                    :disabled="!isAdmin"
                                />
                                <p v-if="generalForm.errors.email" :class="fieldError">{{ generalForm.errors.email }}</p>
                            </div>
                            <div>
                                <label for="phone" :class="fieldLabel">Phone</label>
                                <input
                                    id="phone"
                                    v-model="generalForm.phone"
                                    type="text"
                                    :class="fieldInput"
                                    :disabled="!isAdmin"
                                />
                                <p v-if="generalForm.errors.phone" :class="fieldError">{{ generalForm.errors.phone }}</p>
                            </div>
                            <div>
                                <label for="address" :class="fieldLabel">Address</label>
                                <input
                                    id="address"
                                    v-model="generalForm.address"
                                    type="text"
                                    :class="fieldInput"
                                    :disabled="!isAdmin"
                                />
                                <p v-if="generalForm.errors.address" :class="fieldError">{{ generalForm.errors.address }}</p>
                            </div>
                            <div>
                                <label for="city" :class="fieldLabel">City</label>
                                <input
                                    id="city"
                                    v-model="generalForm.city"
                                    type="text"
                                    :class="fieldInput"
                                    :disabled="!isAdmin"
                                />
                                <p v-if="generalForm.errors.city" :class="fieldError">{{ generalForm.errors.city }}</p>
                            </div>
                            <div>
                                <label for="state" :class="fieldLabel">State/Province</label>
                                <input
                                    id="state"
                                    v-model="generalForm.state"
                                    type="text"
                                    :class="fieldInput"
                                    :disabled="!isAdmin"
                                />
                                <p v-if="generalForm.errors.state" :class="fieldError">{{ generalForm.errors.state }}</p>
                            </div>
                            <div>
                                <label for="zip" :class="fieldLabel">ZIP/Postal Code</label>
                                <input
                                    id="zip"
                                    v-model="generalForm.zip"
                                    type="text"
                                    :class="fieldInput"
                                    :disabled="!isAdmin"
                                />
                                <p v-if="generalForm.errors.zip" :class="fieldError">{{ generalForm.errors.zip }}</p>
                            </div>
                            <div>
                                <label for="country" :class="fieldLabel">Country</label>
                                <input
                                    id="country"
                                    v-model="generalForm.country"
                                    type="text"
                                    :class="fieldInput"
                                    :disabled="!isAdmin"
                                />
                                <p v-if="generalForm.errors.country" :class="fieldError">{{ generalForm.errors.country }}</p>
                            </div>
                        </div>

                        <div v-if="isAdmin" class="mt-6 flex justify-end">
                            <Button type="submit" variant="default" :loading="generalForm.processing" :disabled="generalForm.processing">
                                Save Changes
                            </Button>
                        </div>
                    </div>
                </form>
            </Card>
        </div>

        <!-- Regional Settings Tab -->
        <div v-show="activeTab === 'regional'" class="mt-6">
            <Card :padded="false">
                <form @submit.prevent="submitRegional">
                    <div class="px-5 pt-5">
                        <h3 class="text-sm font-semibold text-text-primary">Regional Settings</h3>
                    </div>
                    <div class="p-5">
                        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                            <div>
                                <label for="currency" :class="fieldLabel">Currency</label>
                                <input
                                    id="currency"
                                    v-model="regionalForm.currency"
                                    type="text"
                                    :class="fieldInput"
                                    :disabled="!isAdmin"
                                    placeholder="USD"
                                />
                                <p v-if="regionalForm.errors.currency" :class="fieldError">{{ regionalForm.errors.currency }}</p>
                            </div>
                            <div>
                                <label for="timezone" :class="fieldLabel">Timezone</label>
                                <input
                                    id="timezone"
                                    v-model="regionalForm.timezone"
                                    type="text"
                                    :class="fieldInput"
                                    :disabled="!isAdmin"
                                    placeholder="UTC"
                                />
                                <p v-if="regionalForm.errors.timezone" :class="fieldError">{{ regionalForm.errors.timezone }}</p>
                            </div>
                            <div>
                                <label for="date_format" :class="fieldLabel">Date Format</label>
                                <input
                                    id="date_format"
                                    v-model="regionalForm.date_format"
                                    type="text"
                                    :class="fieldInput"
                                    :disabled="!isAdmin"
                                    placeholder="Y-m-d"
                                />
                                <p v-if="regionalForm.errors.date_format" :class="fieldError">{{ regionalForm.errors.date_format }}</p>
                            </div>
                            <div>
                                <label for="time_format" :class="fieldLabel">Time Format</label>
                                <input
                                    id="time_format"
                                    v-model="regionalForm.time_format"
                                    type="text"
                                    :class="fieldInput"
                                    :disabled="!isAdmin"
                                    placeholder="H:i"
                                />
                                <p v-if="regionalForm.errors.time_format" :class="fieldError">{{ regionalForm.errors.time_format }}</p>
                            </div>
                        </div>

                        <div v-if="isAdmin" class="mt-6 flex justify-end">
                            <Button type="submit" variant="default" :loading="regionalForm.processing" :disabled="regionalForm.processing">
                                Save Changes
                            </Button>
                        </div>
                    </div>
                </form>
            </Card>
        </div>

        <!-- AI Settings Tab -->
        <div v-show="activeTab === 'ai'" class="mt-6">
            <Card :padded="false">
                <form @submit.prevent="submitAi">
                    <div class="px-5 pt-5">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 class="text-sm font-semibold text-text-primary">AI 设置</h3>
                                <p class="mt-1 text-sm text-text-secondary">
                                    配置 MiniMax，用于每周销量页面的 AI 批量修改库存和成本。
                                </p>
                            </div>
                            <span
                                :class="[
                                    'inline-flex w-fit rounded-full px-2.5 py-1 text-xs font-medium',
                                    aiSettings.minimax_configured
                                        ? 'bg-status-success-soft text-status-success'
                                        : 'bg-status-warning-soft text-status-warning'
                                ]"
                            >
                                {{ aiSettings.minimax_configured ? 'API Key 已配置' : 'API Key 未配置' }}
                            </span>
                        </div>
                    </div>
                    <div class="p-5">
                        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                            <div class="md:col-span-2">
                                <label for="minimax_api_key" :class="fieldLabel">MiniMax API Key</label>
                                <input
                                    id="minimax_api_key"
                                    v-model="aiForm.minimax_api_key"
                                    type="password"
                                    autocomplete="new-password"
                                    :class="fieldInput"
                                    :disabled="!isAdmin"
                                    :placeholder="aiSettings.minimax_configured ? '留空则继续使用现有 Key' : '请输入 MiniMax API Key'"
                                />
                                <p class="mt-1 text-xs text-text-tertiary">
                                    API Key 会在服务器加密保存；保存后不会再次完整显示。
                                </p>
                                <p v-if="aiForm.errors.minimax_api_key" :class="fieldError">{{ aiForm.errors.minimax_api_key }}</p>
                            </div>
                            <div>
                                <label for="minimax_base_url" :class="fieldLabel">MiniMax Base URL</label>
                                <input
                                    id="minimax_base_url"
                                    v-model="aiForm.minimax_base_url"
                                    type="url"
                                    :class="fieldInput"
                                    :disabled="!isAdmin"
                                    placeholder="https://api.minimax.io/v1"
                                />
                                <p v-if="aiForm.errors.minimax_base_url" :class="fieldError">{{ aiForm.errors.minimax_base_url }}</p>
                            </div>
                            <div>
                                <label for="minimax_model" :class="fieldLabel">MiniMax 模型</label>
                                <input
                                    id="minimax_model"
                                    v-model="aiForm.minimax_model"
                                    type="text"
                                    :class="fieldInput"
                                    :disabled="!isAdmin"
                                    placeholder="MiniMax-M2.7"
                                />
                                <p v-if="aiForm.errors.minimax_model" :class="fieldError">{{ aiForm.errors.minimax_model }}</p>
                            </div>
                        </div>

                        <div v-if="isAdmin" class="mt-6 flex justify-end">
                            <Button type="submit" variant="default" :loading="aiForm.processing" :disabled="aiForm.processing">
                                保存 AI 设置
                            </Button>
                        </div>
                    </div>
                </form>
            </Card>
        </div>

        <!-- User Management Tab -->
        <div v-show="activeTab === 'users' && isAdmin" class="mt-6">
            <Card>
                <div class="flex flex-col items-center gap-3 py-12 text-center">
                    <Users :size="40" class="text-text-tertiary" />
                    <h3 class="text-sm font-semibold text-text-primary">User Management</h3>
                    <p class="text-sm text-text-secondary">
                        User management functionality is available in the Users section.
                    </p>
                    <Button variant="default" as="Link" :href="route('users.index')">
                        Go to User Management
                    </Button>
                </div>
            </Card>
        </div>
    </AppLayout>
</template>
