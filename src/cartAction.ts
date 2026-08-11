import { sendRegistrationRequest } from "itmar-block-packages";
import type {
	CartActionResponse,
	CartLineNode,
	ProductData,
} from "./types";

export type CartRequestData = Record<string, unknown>;

interface BindCartParams {
	cartId: string;
	accessToken: string;
}

/**
 * cart/lines を叩く共通関数
 * 返り値は PHP 側のレスポンスをそのまま返す（DOM操作しない）
 */
export async function cartLinesRequest(
	postData: CartRequestData,
): Promise<CartActionResponse> {
	const targetUrl = "/wp-json/itmar-ec-relate/v1/cart/lines";
	return (await sendRegistrationRequest(
		targetUrl,
		postData,
		"rest",
	)) as CartActionResponse;
}

/**
 * 「顧客トークンでカートを昇格（bind）」する
 */
export async function bindCartToCustomer({
	cartId,
	accessToken,
}: BindCartParams): Promise<unknown> {
	const targetUrl = "/wp-json/itmar-ec-relate/v1/cart/bind";
	const postData = {
		cart_id: cartId,
		customer_token: accessToken,
		nonce: itmar_option.nonce,
	};
	return await sendRegistrationRequest(targetUrl, postData, "rest");
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
