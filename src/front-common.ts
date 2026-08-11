import { displayFormated } from "itmar-block-packages";
import type { JQueryCollection } from "./types";

const $ = window.jQuery;

export function getCookie(name: string): string | null {
	const match = document.cookie.match(new RegExp("(^| )" + name + "=([^;]+)"));
	if (match) return match[2];
	return null;
}

/**
 * Design Title のテキスト埋め込み（jQuery要）
 * @param {any} embedText
 * @param {JQuery} embedDom
 */
export function textEmbed(
	embedText: unknown,
	embedDom: JQueryCollection,
): void {
	if (!$) {
		console.warn("[itmar] jQuery is missing: textEmbed()");
		return;
	}

	const displayText =
		embedText != null
			? displayFormated(
					embedText,
					embedDom.data("user_format"),
					embedDom.data("free_format"),
					embedDom.data("decimal"),
			  )
			: null;

	embedDom.find("h1,h2,h3,h4,h5,h6").each(function (this: HTMLElement) {
		//const $div = $(this).find("div");
		if ($(this).length > 0) {
			$(this).text(displayText);
		}
	});
}
