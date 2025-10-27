import { forwardRef } from "@wordpress/element";
import { cn } from "./utils";

export const Card = forwardRef(
	( { className = "", elevated = false, children, ...props }, ref ) => (
		<div
			ref={ ref }
			className={ cn( "shadcn-card", elevated && "shadcn-card--levelled", className ) }
			{ ...props }
		>
			{ children }
		</div>
	)
);

Card.displayName = "Card";

export const CardHeader = forwardRef(
	( { className = "", children, ...props }, ref ) => (
		<div
			ref={ ref }
			className={ cn( "shadcn-card__header", className ) }
			{ ...props }
		>
			{ children }
		</div>
	)
);

CardHeader.displayName = "CardHeader";

export const CardTitle = forwardRef(
	( { className = "", children, ...props }, ref ) => (
		<h3
			ref={ ref }
			className={ cn( "shadcn-card__title", className ) }
			{ ...props }
		>
			{ children }
		</h3>
	)
);

CardTitle.displayName = "CardTitle";

export const CardDescription = forwardRef(
	( { className = "", children, ...props }, ref ) => (
		<p
			ref={ ref }
			className={ cn( "shadcn-card__description", className ) }
			{ ...props }
		>
			{ children }
		</p>
	)
);

CardDescription.displayName = "CardDescription";

export const CardContent = forwardRef(
	( { className = "", children, ...props }, ref ) => (
		<div
			ref={ ref }
			className={ cn( "shadcn-card__content", className ) }
			{ ...props }
		>
			{ children }
		</div>
	)
);

CardContent.displayName = "CardContent";

export const CardFooter = forwardRef(
	( { className = "", children, ...props }, ref ) => (
		<div
			ref={ ref }
			className={ cn( "shadcn-card__footer", className ) }
			{ ...props }
		>
			{ children }
		</div>
	)
);

CardFooter.displayName = "CardFooter";
