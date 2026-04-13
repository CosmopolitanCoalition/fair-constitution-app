<template>
    <div class="h-screen overflow-hidden flex flex-col bg-gray-950 text-gray-100">
        <nav class="bg-gray-900 border-b border-gray-800 px-4 py-3 flex items-center gap-4 shrink-0">
            <a href="/" class="text-lg font-semibold tracking-tight text-white hover:text-blue-400 transition-colors">
                Fair Constitution
            </a>
            <span class="text-gray-600">|</span>
            <a href="/jurisdictions" class="text-sm text-gray-400 hover:text-white transition-colors">
                Jurisdictions
            </a>
        </nav>
        <main class="flex-1 flex flex-col overflow-y-auto">
            <slot />
        </main>
    </div>
</template>
