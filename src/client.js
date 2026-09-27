import { usePanel } from "kirbyuse"

export function clientTitle(client) {
	const panel = usePanel()

	return client.host
		? panel.t("agents.authorize.client.verified", { host: client.host })
		: panel.t("agents.authorize.client.unverified")
}
