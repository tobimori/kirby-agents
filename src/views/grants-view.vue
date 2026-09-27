<template>
	<k-panel-inside class="k-agents-grants-view">
		<k-header>
			{{ $t("agents.title") }}

			<template #buttons>
				<k-input :before="$t('agents.grants.url')" class="k-agents-grants-url" type="text">
					<input :value="url" class="k-string-input" readonly @focus="$event.target.select()" />
					<template #icon>
						<k-button :title="$t('copy')" class="k-input-icon-button" icon="copy" @click="copy" />
					</template>
				</k-input>
			</template>
		</k-header>

		<k-empty v-if="grants.length === 0" icon="ai">
			{{ $t("agents.grants.empty") }}
		</k-empty>

		<k-table
			v-else
			:columns="columns"
			:index="false"
			:options="options"
			:rows="grants"
			@option="onOption"
		/>
	</k-panel-inside>
</template>

<script setup>
import { computed, useHelpers, usePanel } from "kirbyuse"

const props = defineProps({
	all: Boolean,
	grants: Array,
	url: String
})

const panel = usePanel()
const helpers = useHelpers()

const columns = computed(() => ({
	client: { label: panel.t("agents.grants.agent"), type: "agents-client", mobile: true },
	...(props.all ? { user: { label: panel.t("user"), type: "agents-user", width: "1/5" } } : {}),
	scopes: { label: panel.t("agents.grants.scopes"), type: "agents-scopes" },
	active: { label: panel.t("agents.grants.active"), type: "agents-time", width: "1/6" }
}))

const options = computed(() => [
	{ icon: "key", text: panel.t("agents.grants.scopes.change"), click: "scopes" },
	"-",
	{
		icon: "cancel",
		text: panel.t("agents.grants.revoke"),
		click: "revoke",
		theme: "negative"
	}
])

function copy() {
	helpers.clipboard.write(props.url)
	panel.notification.success(panel.t("copy.success"))
}

function onOption(option, row) {
	panel.dialog.open(row.dialogs[option])
}
</script>

<style>
.k-agents-grants-view {
	.k-header-buttons {
		align-self: center;
	}

	.k-table tbody tr:last-child {
		td:first-child {
			border-end-start-radius: var(--rounded);
		}

		td:last-child {
			border-end-end-radius: var(--rounded);
		}
	}
}

.k-agents-grants-url {
	--input-height: var(--height-sm);
	--input-font-size: var(--text-sm);
	width: min(26rem, 60vw);

	input {
		width: 100%;
		padding-block: 0;
	}
}
</style>
