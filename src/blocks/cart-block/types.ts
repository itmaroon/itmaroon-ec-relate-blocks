export interface CartField {
	key: string;
	label: string;
	block: string;
}

export type SerializedBlock = Record<string, unknown>;

export interface CartBlockAttributes {
	storefrontToken?: string;
	selectedFields: CartField[];
	numberOfItems: number;
	cartId?: string;
	cartIconId?: string;
	blocksAttributesArray: SerializedBlock[];
}

export interface CartBlockEditProps {
	attributes: CartBlockAttributes;
	setAttributes: (attributes: Partial<CartBlockAttributes>) => void;
	clientId: string;
}

export interface CartBlockSaveProps {
	attributes: CartBlockAttributes;
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

export interface CartContext {
	cartRoot: HTMLElement;
	modal_id: string | null;
	cart_icon_id: string | null;
	rawCartId: string;
	wp_user_id: string;
	shopify_authenticated: boolean;
}
