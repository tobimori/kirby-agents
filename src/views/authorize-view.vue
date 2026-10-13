<template>
	<k-panel-outside class="k-agents-authorize-view">
		<div class="k-dialog k-login k-agents-authorize">
			<div class="k-dialog-body">
				<k-box
					v-if="error"
					theme="negative"
					icon="alert"
					:text="$t(`agents.authorize.error.${error}`)"
				/>

				<form v-else ref="form" method="post" :action="action">
					<input type="hidden" name="csrf" :value="csrf" />
					<input type="hidden" name="decision" :value="decision" />

					<h1 class="k-agents-authorize-title">
						{{ $t("agents.authorize.title", { client: client.name, site }) }}
					</h1>

					<k-text class="k-agents-authorize-text">
						<p>{{ $t("agents.authorize.user", { user }) }}</p>
						<p>{{ clientTitle(client) }}</p>
					</k-text>

					<p class="k-agents-authorize-label">
						{{ $t("agents.authorize.scopes", { client: client.name }) }}
					</p>
					<ul class="k-agents-authorize-scopes">
						<li v-for="scope in rows" :key="scope.id" :data-on="String(scope.on)">
							<label>
								<input
									type="checkbox"
									name="scopes[]"
									:value="scope.id"
									:checked="scope.on"
									:disabled="scope.disabled"
									@change="toggle(scope.id, $event.target.checked)"
								/>
								<span class="k-agents-authorize-scope-text">
									<span>{{ scope.text }}</span>
									<small v-if="scope.new" class="k-agents-authorize-scope-new">
										{{ $t("agents.authorize.scope.new") }}
									</small>
									<small v-else-if="scope.note">{{ scope.note }}</small>
								</span>
							</label>
						</li>
					</ul>
					<p class="k-agents-authorize-hint">{{ $t("agents.authorize.scopes.help") }}</p>

					<k-box
						:theme="redirect.type === 'web' ? 'info' : 'notice'"
						:text="$t(`agents.authorize.redirect.${redirect.type}`, { host: redirect.host })"
					/>

					<div class="k-agents-authorize-buttons">
						<k-button
							:disabled="submitting"
							:text="$t('agents.authorize.deny')"
							size="lg"
							variant="filled"
							@click="submit('deny')"
						/>
						<k-button
							:disabled="submitting"
							:text="$t('agents.authorize.allow')"
							size="lg"
							theme="positive"
							variant="filled"
							@click="submit('approve')"
						/>
					</div>
				</form>
			</div>
		</div>
	</k-panel-outside>
</template>

<script setup>
import { computed, nextTick, ref, usePanel } from "kirbyuse"

import { clientTitle } from "../client.js"

const props = defineProps({
	action: String,
	client: Object,
	csrf: String,
	error: String,
	redirect: Object,
	scopes: { type: Array, default: () => [] },
	site: String,
	user: String
})

const panel = usePanel()
const form = ref(null)
const decision = ref("deny")
const submitting = ref(false)

// the scopes that the user turned on, starting with the requested ones
const selected = ref(
	props.scopes.filter((scope) => scope.requested && scope.allowed).map((scope) => scope.id)
)

const rows = computed(() =>
	props.scopes.map((scope) => {
		// a scope that is on includes others, like publishing includes preparing changes
		const by = props.scopes.find(
			(other) => selected.value.includes(other.id) && other.includes.includes(scope.id)
		)

		return {
			...scope,
			on: scope.allowed && (scope.required || !!by || selected.value.includes(scope.id)),
			disabled: !scope.allowed || scope.required || !!by,
			note: !scope.allowed
				? panel.t("agents.authorize.scope.unavailable")
				: scope.required
					? panel.t("agents.authorize.scope.required")
					: by
						? panel.t("agents.authorize.scope.included", { scope: by.short })
						: null
		}
	})
)

function toggle(id, on) {
	selected.value = on
		? [...selected.value, id]
		: selected.value.filter((selectedId) => selectedId !== id)
}

async function submit(value) {
	decision.value = value
	submitting.value = true
	// the hidden input must have the decision before the post
	await nextTick()
	form.value.submit()
}
</script>

<style>
.k-agents-authorize {
	--dialog-width: 30rem;
	line-height: 1.5;

	.k-dialog-body {
		padding-bottom: var(--dialog-padding);
	}
}

.k-agents-authorize-title {
	font-size: var(--text-xl);
	font-weight: var(--font-bold);
	line-height: 1.25;
	margin-bottom: var(--spacing-3);
}

.k-agents-authorize-text {
	color: var(--color-text-dimmed);
	margin-bottom: var(--spacing-6);
}

.k-agents-authorize-label {
	font-weight: var(--font-bold);
	margin-bottom: var(--spacing-2);
}

.k-agents-authorize-scopes {
	display: grid;
	gap: var(--spacing-3);

	label {
		display: flex;
		align-items: flex-start;
		gap: var(--spacing-3);
	}

	input {
		flex-shrink: 0;
		top: 3px;
	}
}

.k-agents-authorize-scope-text {
	display: grid;
	line-height: 1.375;

	[data-on="false"] & {
		color: var(--color-text-dimmed);
	}

	small {
		font-size: var(--text-xs);
		color: var(--color-text-dimmed);
	}

	.k-agents-authorize-scope-new {
		font-weight: var(--font-bold);
		color: var(--color-focus);
	}
}

.k-agents-authorize-hint {
	font-size: var(--text-xs);
	color: var(--color-text-dimmed);
	margin-top: var(--spacing-3);
	margin-bottom: var(--spacing-6);
}

.k-agents-authorize-buttons {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: var(--spacing-3);
	margin-top: var(--spacing-6);
}
</style>
