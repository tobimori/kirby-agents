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

					<p class="k-agents-authorize-label">{{ $t("agents.authorize.scopes") }}</p>
					<ul class="k-agents-authorize-scopes">
						<li v-for="scope in scopes" :key="scope.id" :data-allowed="scope.allowed">
							<k-icon :type="scope.allowed ? 'check' : 'cancel'" />
							<span>{{ $t(`agents.scope.${scope.id}`) }}</span>
							<small v-if="!scope.allowed">
								{{ $t("agents.authorize.scope.unavailable") }}
							</small>
						</li>
					</ul>

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
import { nextTick, ref } from "kirbyuse"

import { clientTitle } from "../client.js"

defineProps({
	action: String,
	client: Object,
	csrf: String,
	error: String,
	redirect: Object,
	scopes: Array,
	site: String,
	user: String
})

const form = ref(null)
const decision = ref("deny")
const submitting = ref(false)

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
	gap: var(--spacing-2);
	margin-bottom: var(--spacing-6);

	li {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: var(--spacing-2);

		&[data-allowed="true"] .k-icon {
			color: var(--color-positive);
		}

		&[data-allowed="false"] {
			color: var(--color-text-dimmed);
		}
	}

	small {
		font-size: var(--text-xs);
	}
}

.k-agents-authorize-buttons {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: var(--spacing-3);
	margin-top: var(--spacing-6);
}
</style>
