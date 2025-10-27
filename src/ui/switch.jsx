import { forwardRef } from "@wordpress/element";
import { cn } from "./utils";

export const Switch = forwardRef(
	(
		{
			className = "",
			label = "",
			description = "",
			checked = false,
			onChange = () => {},
			disabled = false,
			...props
		},
		ref
	) => {
		const handleToggle = ( event ) => {
			event.preventDefault();
			if ( disabled ) {
				return;
			}

			onChange( ! checked );
		};

		const handleKeyDown = ( event ) => {
			if ( event.key === "Enter" || event.key === " " ) {
				event.preventDefault();
				handleToggle( event );
			}
		};

		return (
			<button
				ref={ ref }
				type="button"
				role="switch"
				aria-checked={ checked }
				aria-disabled={ disabled }
				data-state={ checked ? "checked" : "unchecked" }
				className={ cn( "shadcn-switch", className ) }
				onClick={ handleToggle }
				onKeyDown={ handleKeyDown }
				{ ...props }
			>
				<span className="shadcn-switch__track" aria-hidden="true">
					<span className="shadcn-switch__thumb" />
				</span>
				<span className="shadcn-switch__label">
					<span className="shadcn-switch__title">{ label }</span>
					{ description && (
						<span className="shadcn-switch__description">{ description }</span>
					) }
				</span>
			</button>
		);
	}
);

Switch.displayName = "Switch";
