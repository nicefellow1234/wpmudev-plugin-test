import { forwardRef } from "@wordpress/element";
import { cn } from "./utils";

export const Alert = forwardRef(
	(
		{
			variant = "info",
			title = "",
			description = "",
			children = null,
			className = "",
			...props
		},
		ref
	) => {
		const variantClass = variant ? `shadcn-alert--${ variant }` : "";

		return (
			<div
				ref={ ref }
				className={ cn( "shadcn-alert", variantClass, className ) }
				role="status"
				{ ...props }
			>
				<div>
					{ title && <div className="shadcn-alert__title">{ title }</div> }
					{ description && (
						<div className="shadcn-alert__description">{ description }</div>
					) }
					{ children }
				</div>
			</div>
		);
	}
);

Alert.displayName = "Alert";
