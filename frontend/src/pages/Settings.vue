<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { getJSON, sendJSON } from '../api'
const thr = ref(0.15)
const msg = ref('')
const err = ref('')
onMounted(async () => {
  const d = await getJSON<{ residual_threshold_m: number }>('/api/settings')
  thr.value = d.residual_threshold_m
})
async function save() {
  err.value = ''; msg.value = ''
  try {
    const d = await sendJSON<{ residual_threshold_m: number }>('/api/settings', 'PUT', { residual_threshold_m: thr.value })
    thr.value = d.residual_threshold_m
    msg.value = '已保存'
  } catch (e) { err.value = String(e) }
}
</script>
<template>
  <div class="page">
    <h1>残差阈值</h1>
    <div class="panel">
      <label>residual_threshold_m <input type="number" step="0.01" v-model.number="thr" /></label>
      <button style="margin-left: 8px" @click="save">保存</button>
      <p v-if="msg">{{ msg }}</p>
      <p v-if="err" class="err">{{ err }}</p>
    </div>
  </div>
</template>
