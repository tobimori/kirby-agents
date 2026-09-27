import ClientCell from "./cells/client-cell.vue"
import ScopesCell from "./cells/scopes-cell.vue"
import TimeCell from "./cells/time-cell.vue"
import UserCell from "./cells/user-cell.vue"
import AuthorizeView from "./views/authorize-view.vue"
import GrantsView from "./views/grants-view.vue"
import webmcp from "./webmcp.js"

panel.plugin("tobimori/agents", {
	components: {
		"k-agents-authorize-view": AuthorizeView,
		"k-agents-grants-view": GrantsView,
		"k-table-agents-client-cell": ClientCell,
		"k-table-agents-scopes-cell": ScopesCell,
		"k-table-agents-time-cell": TimeCell,
		"k-table-agents-user-cell": UserCell
	},
	created: webmcp
})
