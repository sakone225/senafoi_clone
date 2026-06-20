<script setup>
// Page vide — SENAFAD / Option 1
// À remplacer par le contenu réel quand il sera prêt.
</script>

<template>
  <div class="empty-page">
    <div class="empty-card">
      <div class="empty-icon">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
          <rect x="3" y="3" width="18" height="18" rx="3" />
          <path d="M8 12h8M8 16h5" />
        </svg>
      </div>
      <h2>SENAFAD — Option 1</h2>
      <p>Cette page est vide pour le moment.</p>
    </div>
  </div>
</template>

<style scoped>
.empty-page {
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding-top: 40px;
}

.empty-card {
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

.empty-icon {
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

.empty-card h2 {
  font-size: 16px;
  font-weight: 620;
  color: var(--text, #111827);
  margin: 0;
  letter-spacing: -0.02em;
}

.empty-card p {
  font-size: 13.5px;
  color: var(--text-2, #6b7280);
  margin: 0;
  line-height: 1.5;
}
</style>