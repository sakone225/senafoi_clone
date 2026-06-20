<script setup>
import { useRoute } from 'vue-router'
import { computed } from 'vue'

const route = useRoute()
const title = computed(() => route.meta?.title || '')
</script>

<template>
  <div class="placeholder-page">
    <div class="placeholder-card">
      <div class="placeholder-icon">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
          <rect x="3" y="3" width="18" height="18" rx="3" />
          <path d="M8 12h8M8 16h5" />
        </svg>
      </div>
      <h2>{{ title }}</h2>
      <p>Le contenu de cette page n'a pas encore été configuré.</p>
    </div>
  </div>
</template>

<style scoped>
.placeholder-page {
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding-top: 40px;
}

.placeholder-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 10px;
  max-width: 360px;
  padding: 36px 28px;
  background: var(--surface, #fff);
  border: 1px solid var(--border, #e5e7eb);
  border-radius: 14px;
}

.placeholder-icon {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  background: var(--bg, #f4f4f5);
  color: var(--text-2, #6b7280);
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 4px;
}

.placeholder-card h2 {
  font-size: 16px;
  font-weight: 620;
  color: var(--text, #111827);
  margin: 0;
  letter-spacing: -0.02em;
}

.placeholder-card p {
  font-size: 13.5px;
  color: var(--text-2, #6b7280);
  margin: 0;
  line-height: 1.5;
}
</style>