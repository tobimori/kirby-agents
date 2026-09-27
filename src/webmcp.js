import { usePanel, watch } from "kirbyuse"

export default function webmcp() {
	const panel = usePanel()
	const modelContext = document.modelContext ?? navigator.modelContext

	if (typeof modelContext?.registerTool !== "function") {
		return
	}

	let controller = null

	const unregister = () => {
		controller?.abort()
		controller = null
	}

	const register = async () => {
		unregister()
		const current = new AbortController()
		controller = current

		let definitions

		try {
			definitions = await panel.api.get("agents/tools", {}, { silent: true })
		} catch {
			return
		}

		if (definitions.enabled !== true || current.signal.aborted) {
			return
		}

		const tools = [...definitions.tools.map((tool) => serverTool(panel, tool)), viewTool(panel)]

		for (const tool of tools) {
			try {
				await modelContext.registerTool(tool, { signal: current.signal })
			} catch (error) {
				console.warn(`Kirby Agents: could not register the WebMCP tool ${tool.name}`, error)
			}
		}
	}

	watch(
		() => panel.user.id,
		(id) => (id ? register() : unregister()),
		{ immediate: true }
	)
}

function serverTool(panel, tool) {
	return {
		...tool,
		async execute(input, options = {}) {
			let result

			try {
				result = await panel.api.post(`agents/tools/${tool.name}`, input ?? {}, {
					signal: options.signal,
					silent: true
				})
			} catch (error) {
				// a rejected promise reaches the agent without the message
				return { content: [{ type: "text", text: error.message ?? String(error) }], isError: true }
			}

			if (result.isError !== true && tool.annotations.readOnlyHint !== true) {
				panel.view.reload()
			}

			return result
		}
	}
}

function viewTool(panel) {
	return {
		name: "panel_view",
		title: "Current Panel view",
		description:
			'Returns what the user has open in the Kirby Panel: the `id` of the page, file, or site, and the content `language`. Use the `id` as `page` with the other tools, for example when the user says "this page".',
		inputSchema: { type: "object", properties: {} },
		annotations: { readOnlyHint: true },
		async execute() {
			return {
				...parseViewPath(panel.view.path ?? ""),
				title: panel.view.title ?? null,
				language: panel.language?.code ?? null
			}
		}
	}
}

export function parseViewPath(path) {
	const id = (value) => decodeURIComponent(value).replaceAll("+", "/")
	const page = path.match(/^pages\/([^/]+)(?:\/files\/([^/]+))?$/)

	if (page) {
		return page[2]
			? { type: "file", id: `${id(page[1])}/${decodeURIComponent(page[2])}` }
			: { type: "page", id: id(page[1]) }
	}

	const site = path.match(/^site(?:\/files\/([^/]+))?$/)

	if (site) {
		return site[1]
			? { type: "file", id: decodeURIComponent(site[1]) }
			: { type: "site", id: "site" }
	}

	return { type: "other", id: null, view: path }
}
