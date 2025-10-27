import { forwardRef } from "@wordpress/element";
import { cn } from "./utils";

export const Input = forwardRef(
	( { className = "", type = "text", ...props }, ref ) => (
		<input
			ref={ ref }
			type={ type }
			className={ cn( "shadcn-input", className ) }
			{ ...props }
		/>
	)
);

Input.displayName = "Input";
