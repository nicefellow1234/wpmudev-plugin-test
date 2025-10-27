import { forwardRef } from "@wordpress/element";
import { cn } from "./utils";
import { Spinner } from "./spinner";

export const Button = forwardRef(
	(
		{
			variant = "primary",
			size = "md",
			isLoading = false,
			icon = null,
			iconPosition = "left",
			className = "",
			children,
			disabled = false,
			type,
			...props
		},
		ref
	) => {
		const variantClass = variant ? `shadcn-button--${ variant }` : "";
		const sizeClass = size !== "md" ? `shadcn-button--${ size }` : "";
		const composedDisabled = disabled || isLoading;

		return (
			<button
				ref={ ref }
				type={ type || "button" }
				className={ cn(
					"shadcn-button",
					variantClass,
					sizeClass,
					isLoading && "is-loading",
					className
				) }
				disabled={ composedDisabled }
				data-variant={ variant }
				{ ...props }
			>
				{ icon && iconPosition === "left" && (
					<span className="shadcn-button__icon" aria-hidden="true">
						{ icon }
					</span>
				) }
				<span>{ children }</span>
				{ icon && iconPosition === "right" && (
					<span className="shadcn-button__icon" aria-hidden="true">
						{ icon }
					</span>
				) }
				{ isLoading && (
					<Spinner
						className="shadcn-button__spinner"
						size="sm"
						role="status"
						aria-live="assertive"
					/>
				) }
			</button>
		);
	}
);

Button.displayName = "Button";
