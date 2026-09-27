<template>
	<ul class="k-agents-user-cell k-tags-field-preview k-tags">
		<li>
			<k-tag :image="value.image" :link="value.link" :text="value.text" theme="light" />
		</li>
	</ul>
</template>

<script setup>
defineProps({
	value: Object
})
</script>
