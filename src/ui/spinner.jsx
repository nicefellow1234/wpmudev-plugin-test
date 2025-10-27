import { forwardRef } from "@wordpress/element";
import { cn } from "./utils";

export const Spinner = forwardRef( ( { className = "", size = "md", ...props }, ref ) => {
	const sizeClass = size === "sm" ? "shadcn-spinner--sm" : "";

	return (
		<span
			ref={ ref }
			className={ cn( "shadcn-spinner", sizeClass, className ) }
			{ ...props }
		/>
	);
} );

Spinner.displayName = "Spinner";
