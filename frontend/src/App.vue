<script setup lang="ts">
import { RouterLink, RouterView } from 'vue-router'
</script>
<template>
  <div class="shell">
    <header class="tide-bar">
      <div class="brand">潮谐 · Tideharm</div>
      <nav>
        <RouterLink to="/">潮位台</RouterLink>
        <RouterLink to="/stations">港口站</RouterLink>
        <RouterLink to="/settings">阈值</RouterLink>
      </nav>
    </header>
    <RouterView />
  </div>
</template>
