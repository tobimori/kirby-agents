import AuthorizeView from "./views/authorize-view.vue"
import GrantsView from "./views/grants-view.vue"

panel.plugin("tobimori/agents", {
	components: {
		"k-agents-authorize-view": AuthorizeView,
		"k-agents-grants-view": GrantsView
	}
})
