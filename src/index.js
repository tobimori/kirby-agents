import AuthorizeView from "./views/authorize-view.vue"
import GrantsView from "./views/grants-view.vue"
import webmcp from "./webmcp.js"

panel.plugin("tobimori/agents", {
	components: {
		"k-agents-authorize-view": AuthorizeView,
		"k-agents-grants-view": GrantsView
	},
	created: webmcp
})
