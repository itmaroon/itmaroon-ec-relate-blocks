export type UnknownRecord = Record<string, unknown>;

export interface ShopifyField {
	key: string;
	label: string;
	block: string;
}

export interface ShopifyEdge<T> {
	node: T;
}

export interface ShopifyConnection<T> {
	edges: ShopifyEdge<T>[];
}

export interface ShopifyImage {
	url?: string;
	altText?: string;
	width?: number;
	height?: number;
}

export interface ShopifyMediaSource {
	url?: string;
	width?: number;
	height?: number;
}

export interface ShopifyMediaNode extends UnknownRecord {
	mediaContentType?: "IMAGE" | "VIDEO" | string;
	image?: ShopifyImage;
	sources?: ShopifyMediaSource[];
}

export interface ShopifyVariantNode extends UnknownRecord {
	id?: string;
}

export interface ProductData extends UnknownRecord {
	media?: ShopifyConnection<ShopifyMediaNode>;
	variants?: ShopifyConnection<ShopifyVariantNode>;
	lineId?: string;
	quantity?: number;
}

export interface MoneyAmount {
	amount: string | number;
	currencyCode?: string;
}

export interface EstimatedCost {
	subtotalAmount?: MoneyAmount | null;
	totalTaxAmount?: MoneyAmount | null;
	totalAmount?: MoneyAmount | null;
}

export interface CartActionResponse {
	success?: boolean;
	cartContents?: unknown;
	itemCount?: number;
	estimatedCost?: EstimatedCost | null;
	checkoutUrl?: string;
	buyerId?: string | null;
	cartId?: string;
}

export interface CartLineNode {
	id: string;
	quantity: number;
	merchandise: {
		price?: unknown;
		compareAtPrice?: unknown;
		quantityAvailable?: number;
		product: ProductData;
	};
}

export interface JQueryCollection {
	readonly jquery?: string;
	readonly length: number;
	readonly [index: number]: HTMLElement;
	append(content: JQueryCollection | HTMLElement): JQueryCollection;
	attr(name: string): string | undefined;
	attr(name: string, value: string | number): JQueryCollection;
	attr(values: Record<string, unknown>): JQueryCollection;
	children(): JQueryCollection;
	clone(withDataAndEvents?: boolean): JQueryCollection;
	closest(selector: string): JQueryCollection;
	css(name: string, value: string): JQueryCollection;
	css(values: Record<string, string>): JQueryCollection;
	data(): Record<string, unknown>;
	data(name: string): unknown;
	each(callback: (this: HTMLElement, index: number, element: HTMLElement) => void): JQueryCollection;
	empty(): JQueryCollection;
	eq(index: number): JQueryCollection;
	filter(callback: (this: HTMLElement, index: number, element: HTMLElement) => boolean): JQueryCollection;
	find(selector: string): JQueryCollection;
	first(): JQueryCollection;
	hide(): JQueryCollection;
	is(selector: string): boolean;
	not(selector: string): JQueryCollection;
	parent(): JQueryCollection;
	remove(): JQueryCollection;
	removeAttr(name: string): JQueryCollection;
	removeData(name: string): JQueryCollection;
	replaceWith(content: JQueryCollection): JQueryCollection;
	show(): JQueryCollection;
	text(value: unknown): JQueryCollection;
	unwrap(): JQueryCollection;
	val(): unknown;
	val(value: unknown): JQueryCollection;
}

export function isUnknownRecord(value: unknown): value is UnknownRecord {
	return typeof value === "object" && value !== null && !Array.isArray(value);
}
