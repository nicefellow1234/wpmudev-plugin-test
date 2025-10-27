import { forwardRef } from "@wordpress/element";
import { cn } from "./utils";

export const Badge = forwardRef( ( { tone = "accent", className = "", children, ...props }, ref ) => {
	const toneClass = tone ? `shadcn-badge--${ tone }` : "";

	return (
		<span
			ref={ ref }
			className={ cn( "shadcn-badge", toneClass, className ) }
			{ ...props }
		>
			{ children }
		</span>
	);
} );

Badge.displayName = "Badge";
