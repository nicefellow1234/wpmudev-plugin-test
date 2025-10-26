import {
	createRoot,
	render,
	StrictMode,
	useEffect,
	useMemo,
	useState,
	useCallback,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch, { createNonceMiddleware } from '@wordpress/api-fetch';
import {
	Button,
	CheckboxControl,
	Notice,
	Spinner,
	ProgressBar,
	Card,
	CardBody,
} from '@wordpress/components';

import './scss/style.scss';

const config = window.wpmudevPostsMaintenance || {};

if ( config?.nonce ) {
	apiFetch.use( createNonceMiddleware( config.nonce ) );
}

const getDefaultSelection = ( available = [], preferred = [] ) => {
	if ( preferred.length ) {
		return preferred;
	}

	if ( available.length ) {
		return [ available[0].slug ];
	}

	return [];
};

const emptyJob = {
	status: '',
	total: 0,
	processed: 0,
	message: '',
};

const StatCard = ( { label, value, helper, tone = 'default' } ) => (
	<div className={ `pm-stat-card tone-${ tone }` }>
		<span className="pm-stat-card__label">{ label }</span>
		<span className="pm-stat-card__value">{ value }</span>
		{ helper && <span className="pm-stat-card__helper">{ helper }</span> }
	</div>
);

const ProgressBadge = ( { status } ) => {
	const map = {
		pending: __( 'Pending', 'wpmudev-plugin-test' ),
		running: __( 'Running', 'wpmudev-plugin-test' ),
		completed: __( 'Completed', 'wpmudev-plugin-test' ),
		cancelled: __( 'Cancelled', 'wpmudev-plugin-test' ),
		failed: __( 'Failed', 'wpmudev-plugin-test' ),
	};

	return (
		<span className={ `pm-badge status-${ status || 'idle' }` }>
			{ map[ status ] || __( 'Idle', 'wpmudev-plugin-test' ) }
		</span>
	);
};

const App = () => {
	const initialStatus = config.status || {};
	const [job, setJob] = useState( initialStatus.job || emptyJob );
	const [availableTypes, setAvailableTypes] = useState( initialStatus.postTypes || [] );
	const [lastRun, setLastRun] = useState( initialStatus.last_run || null );
	const [notice, setNotice] = useState( null );
	const [isStarting, setIsStarting] = useState( false );
	const [isRefreshing, setIsRefreshing] = useState( false );

	const initialSelection = getDefaultSelection(
		initialStatus.postTypes || [],
		initialStatus.settings?.post_types || []
	);
	const [selectedTypes, setSelectedTypes] = useState( initialSelection );
	const [cronEnabled, setCronEnabled] = useState( initialStatus.settings?.cron_enabled ?? true );
	const [cronTime, setCronTime] = useState( initialStatus.settings?.cron_time ?? '00:00' );

	const jobActive = job && [ 'pending', 'running' ].includes( job.status );
	const percentComplete = useMemo( () => {
		if ( ! job?.total ) {
			return job?.status === 'completed' ? 100 : 0;
		}

		return Math.min( 100, Math.round( ( job.processed / job.total ) * 100 ) );
	}, [ job ] );

	const showNotice = useCallback( ( message, type = 'success' ) => {
		setNotice( { message, type } );
		setTimeout( () => setNotice( null ), 5000 );
	}, [] );

	const togglePostType = ( slug ) => {
		setSelectedTypes( ( current ) => {
			if ( current.includes( slug ) ) {
				return current.filter( ( item ) => item !== slug );
			}
			return [ ...current, slug ];
		} );
	};

	const handleCardToggle = ( slug ) => {
		if ( jobActive ) {
			return;
		}

		togglePostType( slug );
	};

const fetchStatus = useCallback( async ( silent = false ) => {
		if ( ! silent ) {
			setIsRefreshing( true );
		}

		try {
			const response = await apiFetch( {
				path: `/${ config.endpoints.status }`,
				method: 'GET',
			} );

			if ( response?.success ) {
				const status = response.status || {};
				setJob( status.job || emptyJob );
				setLastRun( status.last_run || null );
				setAvailableTypes( status.postTypes || [] );
				if ( ! silent && status.settings?.post_types ) {
					setSelectedTypes( status.settings.post_types );
				}
			}
		} catch ( error ) {
			if ( ! silent ) {
				showNotice( error?.message || __( 'Unable to fetch status.', 'wpmudev-plugin-test' ), 'error' );
			}
		} finally {
			setIsRefreshing( false );
		}
	}, [ showNotice ] );

	useEffect( () => {
		fetchStatus( true );
	}, [ fetchStatus ] );

	useEffect( () => {
		const interval = jobActive ? 4000 : 20000;
		const timer = setInterval( () => fetchStatus( true ), interval );

		return () => clearInterval( timer );
	}, [ jobActive, fetchStatus ] );

	const handleStart = async () => {
		if ( ! selectedTypes.length ) {
			showNotice( __( 'Select at least one post type.', 'wpmudev-plugin-test' ), 'error' );
			return;
		}

		setIsStarting( true );

		try {
			const response = await apiFetch( {
				path: `/${ config.endpoints.start }`,
				method: 'POST',
				data: { post_types: selectedTypes },
			} );

			if ( response?.success ) {
				setJob( response.job );
				showNotice( __( 'Scan scheduled. We will keep updating the progress.', 'wpmudev-plugin-test' ) );
				fetchStatus( true );
				return;
			}

			throw new Error( response?.message || __( 'Unable to start the scan.', 'wpmudev-plugin-test' ) );
		} catch ( error ) {
			showNotice( error?.message || __( 'Unable to start the scan.', 'wpmudev-plugin-test' ), 'error' );
		} finally {
			setIsStarting( false );
		}
	};

	const handleCancel = async () => {
		try {
			const response = await apiFetch( {
				path: `/${ config.endpoints.cancel }`,
				method: 'POST',
			} );

			if ( response?.success ) {
				setJob( response.job );
				showNotice( __( 'Scan cancelled.', 'wpmudev-plugin-test' ) );
				return;
			}

			throw new Error( response?.message || __( 'Unable to cancel the scan.', 'wpmudev-plugin-test' ) );
		} catch ( error ) {
			showNotice( error?.message || __( 'Unable to cancel the scan.', 'wpmudev-plugin-test' ), 'error' );
		}
	};

	const formatTimestamp = ( timestamp ) => {
		if ( ! timestamp ) {
			return __( 'Not available', 'wpmudev-plugin-test' );
		}

		return new Date( timestamp * 1000 ).toLocaleString();
	};

	const lastRunDisplay = lastRun?.completed_at ? formatTimestamp( lastRun.completed_at ) : __( 'Not yet run', 'wpmudev-plugin-test' );
	const postTypesLabel = selectedTypes.length
		? selectedTypes.join( ', ' )
		: __( 'No post types selected', 'wpmudev-plugin-test' );
	const jobStatusLabel = job?.status ? job.status : __( 'Idle', 'wpmudev-plugin-test' );
	const jobHelper = job?.message || __( 'Ready for your next scan.', 'wpmudev-plugin-test' );
	const progressBarClass = jobActive ? 'pm-progressbar pm-progressbar--animated' : 'pm-progressbar';
	const cliCommand = selectedTypes.length
		? `wp wpmudev posts-maintenance scan --post_types=${ selectedTypes.join( ',' ) }`
		: 'wp wpmudev posts-maintenance scan --post_types=post,page';

	return (
		<div className="sui-wrap pm-wrap">
			<div className="pm-hero">
				<div className="pm-hero__content">
					<p className="pm-hero__eyebrow">{ __( 'Automation Suite', 'wpmudev-plugin-test' ) }</p>
					<h1>
						<span>{ __( 'Posts Maintenance', 'wpmudev-plugin-test' ) }</span>
					</h1>
					<p>
						{ __( 'Run health scans over thousands of posts without leaving the page. Choose your targets, fire the scanner, and let our background worker update the `wpmudev_test_last_scan` meta for every entry.', 'wpmudev-plugin-test' ) }
					</p>
					<div className="pm-hero__meta">
						<span>{ sprintf( __( 'Current filter: %s', 'wpmudev-plugin-test' ), postTypesLabel ) }</span>
						<span>•</span>
						<span>{ sprintf( __( 'Last run: %s', 'wpmudev-plugin-test' ), lastRunDisplay ) }</span>
					</div>
				</div>
				<div className="pm-hero__actions">
					<div className="pm-hero__actions-buttons">
						<Button
							className="pm-btn pm-btn--primary"
							onClick={ handleStart }
							isBusy={ isStarting }
							disabled={ isStarting || jobActive || ! selectedTypes.length }
						>
							{ jobActive ? __( 'Scan In Progress…', 'wpmudev-plugin-test' ) : __( 'Scan Posts Now', 'wpmudev-plugin-test' ) }
						</Button>
						<Button
							className="pm-btn pm-btn--ghost"
							onClick={ () => fetchStatus( false ) }
							isBusy={ isRefreshing }
						>
							{ isRefreshing ? __( 'Syncing…', 'wpmudev-plugin-test' ) : __( 'Sync Status Now', 'wpmudev-plugin-test' ) }
						</Button>
						{ jobActive && (
							<Button onClick={ handleCancel } className="pm-btn pm-btn--outline">
								{ __( 'Cancel Scan', 'wpmudev-plugin-test' ) }
							</Button>
						) }
					</div>
					<p className="pm-hero__note">
						{ jobActive
							? __( 'Auto-refreshing every 4 seconds while the scan runs.', 'wpmudev-plugin-test' )
							: __( 'Status refreshes automatically every few seconds.', 'wpmudev-plugin-test' ) }
					</p>
				</div>
			</div>

			<div className="pm-stat-grid">
				<StatCard
					label={ __( 'Job status', 'wpmudev-plugin-test' ) }
					value={ <ProgressBadge status={ job?.status } /> }
					helper={ statusDescriptions[ job?.status ] || jobHelper }
				/>
				<StatCard
					label={ __( 'Items processed', 'wpmudev-plugin-test' ) }
					value={ sprintf( __( '%1$d of %2$d', 'wpmudev-plugin-test' ), job?.processed || 0, job?.total || 0 ) }
					helper={ __( 'Progress auto-updates', 'wpmudev-plugin-test' ) }
				/>
				<StatCard
					label={ __( 'Daily automation', 'wpmudev-plugin-test' ) }
					value={ __( 'Enabled', 'wpmudev-plugin-test' ) }
					helper={ __( 'Runs once every day via WP-Cron', 'wpmudev-plugin-test' ) }
				/>
				<StatCard
					label={ __( 'Last completed scan', 'wpmudev-plugin-test' ) }
					value={ lastRunDisplay }
					helper={ __( 'Stored in “Last Run Summary”', 'wpmudev-plugin-test' ) }
				/>
			</div>

			{ notice && (
				<Notice status={ notice.type } isDismissible onRemove={ () => setNotice( null ) }>
					{ notice.message }
				</Notice>
			) }

			<div className="sui-box" id="pm-post-type-selector">
				<div className="sui-box-header">
					<h2 className="sui-box-title">{ __( 'Post Type Filters', 'wpmudev-plugin-test' ) }</h2>
				</div>
				<div className="sui-box-body">
				<p className="sui-description">
					{ __( 'Select the post types you would like to include in the next maintenance scan.', 'wpmudev-plugin-test' ) }
				</p>
				<div className="pm-post-type-grid">
					{ availableTypes.map( ( type ) => {
						const isSelected = selectedTypes.includes( type.slug );

						return (
							<Card
								key={ type.slug }
								className={ `pm-type-card ${ isSelected ? 'pm-type-card--active' : '' }` }
								onClick={ () => handleCardToggle( type.slug ) }
								tabIndex={ jobActive ? -1 : 0 }
								onKeyDown={ ( event ) => {
									if ( jobActive ) {
										return;
									}

									if ( event.key === 'Enter' || event.key === ' ' ) {
										event.preventDefault();
										handleCardToggle( type.slug );
									}
								} }
							>
								<CardBody>
									<div className="pm-type-card__content">
										<span className="pm-type-card__indicator" aria-hidden="true" />
										<div className="pm-type-card__text">
											<strong className="pm-type-card__label">{ type.label }</strong>
											<span className="pm-type-card__slug">{ type.slug }</span>
										</div>
										<div className="pm-checkbox-hidden" onClick={ ( event ) => event.stopPropagation() }>
											<CheckboxControl
												label={ type.label }
												checked={ isSelected }
												onChange={ () => togglePostType( type.slug ) }
												disabled={ jobActive }
											/>
										</div>
									</div>
								</CardBody>
							</Card>
						);
					} ) }
					{ ! availableTypes.length && (
						<p>{ __( 'No public post types were found.', 'wpmudev-plugin-test' ) }</p>
					) }
				</div>
			</div>
			<div className="sui-box-footer">
					<div className="sui-actions-left">
						<p className="sui-description">
							{ __( 'Filters are saved automatically and reused by the daily cron task.', 'wpmudev-plugin-test' ) }
						</p>
					</div>
				<div className="sui-actions-right">
					<Button
						variant="secondary"
						className="pm-btn pm-btn--ghost"
						onClick={ () => fetchStatus( false ) }
						isBusy={ isRefreshing }
					>
						{ isRefreshing ? __( 'Syncing…', 'wpmudev-plugin-test' ) : __( 'Sync Filters From Server', 'wpmudev-plugin-test' ) }
					</Button>
				</div>
				</div>
			</div>

			<div className="pm-progress-panel">
				<div className="pm-progress-panel__header">
					<div>
						<p className="pm-progress-panel__eyebrow">{ __( 'Scan Progress', 'wpmudev-plugin-test' ) }</p>
						<h3>{ statusDescriptions[ job?.status ] || jobHelper }</h3>
						<p className="pm-progress-panel__meta">
							{ sprintf(
								__( '%1$d of %2$d items processed.', 'wpmudev-plugin-test' ),
								job?.processed || 0,
								job?.total || 0
							) }
						</p>
					</div>
					<ProgressBadge status={ job?.status } />
				</div>
				<ProgressBar value={ percentComplete } className={ progressBarClass } />
				{ job?.message && <p className="pm-status-message">{ job.message }</p> }
				<div className="pm-progress-panel__timeline">
					<div className={ `timeline-step ${ job?.status ? 'active' : '' }` }>
						<span className="dot" />
						<strong>{ __( 'Queued', 'wpmudev-plugin-test' ) }</strong>
						<p>{ __( 'Waiting for background worker.', 'wpmudev-plugin-test' ) }</p>
					</div>
					<div className={ `timeline-step ${ job?.status === 'running' ? 'active' : '' }` }>
						<span className="dot" />
						<strong>{ __( 'Running', 'wpmudev-plugin-test' ) }</strong>
						<p>{ __( 'Processing posts in batches of 25.', 'wpmudev-plugin-test' ) }</p>
					</div>
					<div className={ `timeline-step ${ job?.status === 'completed' ? 'active' : '' }` }>
						<span className="dot" />
						<strong>{ __( 'Completed', 'wpmudev-plugin-test' ) }</strong>
						<p>{ __( 'Meta updated and history recorded.', 'wpmudev-plugin-test' ) }</p>
					</div>
				</div>
			</div>

			<div className="pm-history-grid">
				<div className="sui-box">
					<div className="sui-box-header">
						<h2 className="sui-box-title">{ __( 'Last Run Summary', 'wpmudev-plugin-test' ) }</h2>
					</div>
					<div className="sui-box-body">
						{ lastRun ? (
							<ul className="pm-last-run-list">
								<li>
									<strong>{ __( 'Completed:', 'wpmudev-plugin-test' ) }</strong>{' '}
									{ formatTimestamp( lastRun.completed_at ) }
								</li>
								<li>
									<strong>{ __( 'Post types:', 'wpmudev-plugin-test' ) }</strong>{' '}
									{ ( lastRun.post_types || [] ).join( ', ' ) }
								</li>
								<li>
									<strong>{ __( 'Processed items:', 'wpmudev-plugin-test' ) }</strong>{' '}
									{ lastRun.processed || 0 }
								</li>
							</ul>
						) : (
							<p>{ __( 'No completed scans yet.', 'wpmudev-plugin-test' ) }</p>
						) }
						<p className="sui-description">
							{ __( 'A daily cron task runs automatically using your saved filters. You can rerun the scan manually any time.', 'wpmudev-plugin-test' ) }
						</p>
					</div>
				</div>
				<div className="sui-box pm-cli-box">
					<div className="sui-box-header">
						<h2 className="sui-box-title">{ __( 'WP-CLI Shortcut', 'wpmudev-plugin-test' ) }</h2>
					</div>
					<div className="sui-box-body">
						<p>{ __( 'Run the same maintenance routine directly from the terminal. Perfect for cron jobs or CI pipelines.', 'wpmudev-plugin-test' ) }</p>
					<pre><code>{ cliCommand }</code></pre>
						<p className="sui-description">
							{ __( 'Add `--allow-root` when running inside containers or root shells.', 'wpmudev-plugin-test' ) }
						</p>
					</div>
				</div>
			</div>
		</div>
	);
};

const rootElement = document.getElementById( config.rootId );

if ( rootElement ) {
	const tree = (
		<StrictMode>
			<App />
		</StrictMode>
	);

	if ( createRoot ) {
		createRoot( rootElement ).render( tree );
	} else {
		render( tree, rootElement );
	}
}
	const statusDescriptions = {
		pending: __( 'Queued background scan…', 'wpmudev-plugin-test' ),
		running: __( 'Crunching through posts in small batches.', 'wpmudev-plugin-test' ),
		completed: __( 'Maintenance scan finished successfully.', 'wpmudev-plugin-test' ),
		cancelled: __( 'Scan cancelled by user.', 'wpmudev-plugin-test' ),
		failed: __( 'Something went wrong while scanning.', 'wpmudev-plugin-test' ),
	};
