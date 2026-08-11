declare module "*.scss" {
	const content: unknown;
	export default content;
}

declare module "*.svg" {
	export const ReactComponent: (props: Record<string, unknown>) => unknown;
	const src: string;
	export default src;
}

declare module "react/jsx-runtime" {
	export const jsx: any;
	export const jsxs: any;
	export const Fragment: any;
}

declare namespace JSX {
	interface IntrinsicElements {
		[elementName: string]: any;
	}
}

declare module "@wordpress/api-fetch" {
	export default function apiFetch<T = unknown>(options: {
		path: string;
		method?: string;
		data?: unknown;
		[key: string]: unknown;
	}): Promise<T>;
}

declare module "@wordpress/i18n" {
	export function __(text: string, domain?: string): string;
}

declare module "@wordpress/blocks" {
	export const registerBlockType: any;
}

declare module "@wordpress/block-editor" {
	export const InnerBlocks: any;
	export const InspectorControls: any;
	export const useBlockProps: any;
	export const useInnerBlocksProps: any;
}

declare module "@wordpress/components" {
	export const Button: any;
	export const CheckboxControl: any;
	export const Notice: any;
	export const PanelBody: any;
	export const PanelRow: any;
	export const RangeControl: any;
	export const Spinner: any;
	export const TextControl: any;
}

declare module "@wordpress/element" {
	export function useEffect(
		effect: () => void | (() => void),
		dependencies?: readonly unknown[],
	): void;
	export function useMemo<T>(
		factory: () => T,
		dependencies: readonly unknown[],
	): T;
	export function useRef<T>(initialValue: T): { current: T };
	export function useState<T>(
		initialValue: T | (() => T),
	): [T, (value: T | ((previous: T) => T)) => void];
}

declare module "@wordpress/data" {
	export const dispatch: any;
	export const useDispatch: any;
	export const useSelect: any;
}

declare module "itmar-block-packages" {
	export const ArchiveSelectControl: any;
	export const createBlockTree: any;
	export const registerPickup: any;
	export const sendRegistrationRequest: any;
	export const serializeBlockTree: any;
	export const setState: any;
	export const subscribe: any;
	export const useRebuildChangeField: any;
}

declare const itmar_option: {
	nonce: string;
	home_url: string;
	plugin_url: string;
	isLoggedIn: boolean;
	ajaxUrl?: string;
};

interface Window {
	ajaxurl?: string;
	itmar_option?: typeof itmar_option;
	jQuery?: any;
}
