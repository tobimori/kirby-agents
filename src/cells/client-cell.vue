<template>
	<div class="k-agents-client-cell">
		<span class="k-agents-client-name">{{ value.name }}</span>
		<k-button
			v-if="value.host"
			:text="value.host"
			:title="clientTitle(value)"
			element="span"
			icon="check"
			size="xs"
			theme="positive"
			variant="filled"
		/>
		<span v-else :title="clientTitle(value)" class="k-agents-client-unverified">
			{{ $t("agents.grants.unverified") }}
		</span>
	</div>
</template>

<script setup>
import { clientTitle } from "../client.js"

defineProps({
	value: Object
})
</script>

<style>
.k-agents-client-cell {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: var(--spacing-2);
	padding: 0.325rem var(--table-cell-padding);
}
.k-agents-client-name {
	font-weight: var(--font-bold);
}
.k-agents-client-unverified {
	color: var(--color-text-dimmed);
	font-size: var(--text-xs);
}
</style>
