import { forwardRef } from "@wordpress/element";
import { cn } from "./utils";

export const Select = forwardRef(
	( { className = "", children, ...props }, ref ) => (
		<select
			ref={ ref }
			className={ cn( "shadcn-select", className ) }
			{ ...props }
		>
			{ children }
		</select>
	)
);

Select.displayName = "Select";
