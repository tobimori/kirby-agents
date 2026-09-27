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
			:rows="rows"
			@option="onOption"
		/>
	</k-panel-inside>
</template>

<script>
export default {
	props: {
		all: Boolean,
		grants: Array,
		url: String
	},
	computed: {
		columns() {
			return {
				client: { label: this.$t("agents.grants.agent"), type: "agents-client", mobile: true },
				...(this.all
					? { user: { label: this.$t("user"), type: "agents-user", width: "1/5" } }
					: {}),
				scopes: { label: this.$t("agents.grants.scopes"), type: "agents-scopes" },
				active: { label: this.$t("agents.grants.active"), type: "agents-time", width: "1/6" }
			}
		},
		options() {
			return [
				{ icon: "key", text: this.$t("agents.grants.scopes.change"), click: "scopes" },
				"-",
				{
					icon: "cancel",
					text: this.$t("agents.grants.revoke"),
					click: "revoke",
					theme: "negative"
				}
			]
		},
		rows() {
			return this.grants.map((grant) => ({
				id: grant.id,
				client: grant.client,
				user: grant.user,
				scopes: grant.scopes,
				active: grant.used ?? grant.created,
				dialogs: grant.dialogs
			}))
		}
	},
	methods: {
		async copy() {
			await navigator.clipboard.writeText(this.url)
			this.$panel.notification.success(this.$t("copy.success"))
		},
		onOption(option, row) {
			this.$panel.dialog.open(row.dialogs[option])
		}
	}
}
</script>

<style>
.k-agents-grants-view .k-header-buttons {
	align-self: center;
}
.k-agents-grants-url {
	--input-height: var(--height-sm);
	--input-font-size: var(--text-sm);
	width: min(26rem, 60vw);
}
.k-agents-grants-url input {
	width: 100%;
	padding-block: 0;
}
.k-agents-grants-view .k-table tbody tr:last-child td:first-child {
	border-end-start-radius: var(--rounded);
}
.k-agents-grants-view .k-table tbody tr:last-child td:last-child {
	border-end-end-radius: var(--rounded);
}
</style>
