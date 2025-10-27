import { forwardRef } from "@wordpress/element";
import { cn } from "./utils";

export const Progress = forwardRef(
	( { className = "", value = 0, showLabel = false, label, ...props }, ref ) => {
		const normalized = Math.min( 100, Math.max( 0, Number( value ) || 0 ) );

		return (
			<div
				ref={ ref }
				className={ cn( "shadcn-progress", className ) }
				role="progressbar"
				aria-valuenow={ normalized }
				aria-valuemin="0"
				aria-valuemax="100"
				{ ...props }
			>
				<div className="shadcn-progress__track">
					<div
						className="shadcn-progress__fill"
						style={ { width: `${ normalized }%` } }
					/>
				</div>
				{ showLabel && (
					<span className="shadcn-progress__label">
						{ label || `${ normalized }%` }
					</span>
				) }
			</div>
		);
	}
);

Progress.displayName = "Progress";
