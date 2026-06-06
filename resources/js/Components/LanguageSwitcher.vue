<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { availableLocales, setLocale } from '@/i18n'

const { locale } = useI18n()
const isOpen = ref(false)
const dropdownRef = ref(null)

const currentLocale = () => {
    return availableLocales.find(l => l.code === locale.value) || availableLocales[0]
}

const switchLocale = (code) => {
    setLocale(code)
    isOpen.value = false
}

const handleClickOutside = (event) => {
    if (dropdownRef.value && !dropdownRef.value.contains(event.target)) {
        isOpen.value = false
    }
}

onMounted(() => document.addEventListener('click', handleClickOutside))
onUnmounted(() => document.removeEventListener('click', handleClickOutside))
</script>

<template>
    <div ref="dropdownRef" class="relative">
        <button
            type="button"
            @click="isOpen = !isOpen"
            :aria-label="`Language: ${currentLocale().name}`"
            class="flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-sm text-text-secondary transition hover:bg-surface-overlay hover:text-text-primary ds-focus-ring"
        >
            <span class="text-base">{{ currentLocale().flag }}</span>
            <span class="hidden sm:inline">{{ currentLocale().name }}</span>
            <svg class="w-4 h-4" :class="{ 'rotate-180': isOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
        </button>

        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0 translate-y-1"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 translate-y-1"
        >
            <div
                v-if="isOpen"
                class="absolute right-0 top-full mt-2 z-50 max-h-80 w-48 overflow-y-auto rounded-lg border border-border-subtle bg-surface-raised shadow-xl"
            >
                <button
                    v-for="loc in availableLocales"
                    :key="loc.code"
                    type="button"
                    @click="switchLocale(loc.code)"
                    :class="[
                        'w-full flex items-center gap-3 px-4 py-2.5 text-sm transition',
                        locale === loc.code
                            ? 'bg-brand/10 text-brand'
                            : 'text-text-secondary hover:bg-surface-overlay hover:text-text-primary'
                    ]"
                >
                    <span class="text-base">{{ loc.flag }}</span>
                    <span>{{ loc.name }}</span>
                </button>
            </div>
        </Transition>
    </div>
</template>
