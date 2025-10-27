import { forwardRef } from "@wordpress/element";
import { cn } from "./utils";

export const FormField = forwardRef(
	(
		{
			className = "",
			label = "",
			description = "",
			hint = "",
			children,
			labelFor,
			required = false,
			...props
		},
		ref
	) => (
		<div ref={ ref } className={ cn( "shadcn-form-field", className ) } { ...props }>
			{ label && (
				<label className="shadcn-form-field__label" htmlFor={ labelFor }>
					{ label }
					{ required && <span aria-hidden="true"> *</span> }
				</label>
			) }
			{ description && (
				<p className="shadcn-form-field__description">{ description }</p>
			) }
			{ children }
			{ hint && (
				<small className="shadcn-form-field__description">{ hint }</small>
			) }
		</div>
	)
);

FormField.displayName = "FormField";
