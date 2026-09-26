<template>
	<k-panel-inside class="k-agents-grants-view">
		<k-header>{{ $t("agents.title") }}</k-header>

		<k-box theme="info" class="k-agents-grants-url">
			<span>{{ $t("agents.grants.url") }}</span>
			<code>{{ url }}</code>
			<k-button icon="copy" size="xs" variant="filled" :title="$t('copy')" @click="copy" />
		</k-box>

		<k-empty v-if="grants.length === 0" icon="ai">
			{{ $t("agents.grants.empty") }}
		</k-empty>

		<k-table
			v-else
			:columns="columns"
			:rows="rows"
			:index="false"
			:options="options"
			@option="revoke"
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
				agent: { label: this.$t("name"), type: "html", mobile: true },
				...(this.all ? { user: { label: this.$t("user"), type: "text", width: "1/5" } } : {}),
				scopes: { label: this.$t("agents.authorize.scopes"), type: "tags" },
				active: { label: this.$t("agents.grants.active"), type: "text", width: "1/6" }
			}
		},
		options() {
			return [{ icon: "cancel", text: this.$t("agents.grants.revoke"), click: "revoke" }]
		},
		rows() {
			return this.grants.map((grant) => ({
				// the name comes from the client, so escape it before it is shown as HTML
				agent: `<strong>${this.$helper.string.escapeHTML(grant.name)}</strong><br><small>${this.$helper.string.escapeHTML(grant.host ?? this.$t("agents.grants.self"))}</small>`,
				user: grant.user,
				scopes: grant.scopes.map((scope) => ({ text: scope, value: scope })),
				active: this.date(grant.used ?? grant.created),
				dialog: grant.dialog
			}))
		}
	},
	methods: {
		async copy() {
			await navigator.clipboard.writeText(this.url)
			this.$panel.notification.success(this.$t("copy.success"))
		},
		date(value) {
			return new Date(value).toLocaleString(this.$panel.translation.code, {
				dateStyle: "medium",
				timeStyle: "short"
			})
		},
		revoke(option, row) {
			this.$panel.dialog.open(row.dialog)
		}
	}
}
</script>

<style>
.k-agents-grants-url {
	display: flex;
	align-items: center;
	gap: var(--spacing-3);
	margin-bottom: var(--spacing-6);
}
.k-agents-grants-url code {
	flex-grow: 1;
	font-family: var(--font-mono);
	overflow-wrap: anywhere;
}
</style>
