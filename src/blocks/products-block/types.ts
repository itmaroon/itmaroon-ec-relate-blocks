export interface ProductField {
	key: string;
	label: string;
	block: string;
}

export type SerializedBlock = Record<string, unknown>;

export interface ProductBlockAttributes {
	pickupId: string;
	productPost: string;
	storeUrl?: string;
	shopId?: string;
	channelName?: string;
	categoryArray: unknown[];
	headlessId?: string;
	apiSecretMask?: string;
	adminTokenMask?: string;
	storefrontTokenMask?: string;
	callbackUrl?: string;
	stripeKey?: string;
	selectedFields: ProductField[];
	numberOfItems: number;
	blocksAttributesArray: SerializedBlock[];
}

export interface BlockEditProps {
	attributes: ProductBlockAttributes;
	setAttributes: (attributes: Partial<ProductBlockAttributes>) => void;
	clientId: string;
}

export interface BlockSaveProps {
	attributes: ProductBlockAttributes;
}

export interface ShopifyWebhook {
	id: string;
	topic: string;
	callbackUrl: string;
}
