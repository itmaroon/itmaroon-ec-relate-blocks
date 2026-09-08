import { sendRegistrationRequest } from "itmar-block-packages";
import type {
	CartActionResponse,
	CartLineNode,
	ProductData,
} from "./types";

export type CartRequestData = Record<string, unknown>;

/**
 * cart/lines を叩く共通関数
 * 返り値は PHP 側のレスポンスをそのまま返す（DOM操作しない）
 */
export async function cartLinesRequest(
	postData: CartRequestData,
): Promise<CartActionResponse> {
	const targetUrl = "/itmar-ec-relate/v1/cart/lines";
	return (await sendRegistrationRequest(
		targetUrl,
		postData,
		"rest",
	)) as CartActionResponse;
}

/**
 * cartContents(edge配列) を replaceContent が欲しい形に整形
 */
export function normalizeCartContents(
	cartContentsEdges: unknown,
): ProductData[] {
	if (!Array.isArray(cartContentsEdges)) return [];
	return (cartContentsEdges as Array<{ node: CartLineNode }>).map((edge) => {
		const lineId = edge.node.id;
		const price = edge.node.merchandise.price;
		const compareAtPrice = edge.node.merchandise.compareAtPrice;
		const quantityAvailable = edge.node.merchandise.quantityAvailable;
		const product = edge.node.merchandise.product;
		const quantity = edge.node.quantity;

		return {
			...product,
			lineId,
			price,
			compareAtPrice,
			quantity,
			quantityAvailable,
		};
	});
}
