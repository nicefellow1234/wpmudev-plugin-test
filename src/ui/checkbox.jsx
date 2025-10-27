import { forwardRef } from "@wordpress/element";
import { cn } from "./utils";

export const Checkbox = forwardRef(
	(
		{ className = "", label = "", description = "", disabled = false, ...props },
		ref
	) => (
		<label className={ cn( "shadcn-checkbox", className ) }>
			<input
				ref={ ref }
				type="checkbox"
				disabled={ disabled }
				{ ...props }
			/>
			<span className="shadcn-checkbox__text">
				<span>{ label }</span>
				{ description && (
					<small>{ description }</small>
				) }
			</span>
		</label>
	)
);

Checkbox.displayName = "Checkbox";
