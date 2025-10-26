import {
	createRoot,
	render,
	StrictMode,
	useEffect,
	useMemo,
	useState,
	useCallback,
	useRef,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch, { createNonceMiddleware } from '@wordpress/api-fetch';
import {
	Button,
	CheckboxControl,
	Card,
	CardBody,
	ToggleControl,
	TextControl,
} from '@wordpress/components';

import './scss/style.scss';

const config = window.wpmudevPostsMaintenance || {};

if ( config?.nonce ) {
	apiFetch.use( createNonceMiddleware( config.nonce ) );
}

const statusDescriptions = {
	pending: __( 'Queued background scan...', 'wpmudev-plugin-test' ),
	running: __( 'Crunching through posts in small batches.', 'wpmudev-plugin-test' ),
	cancelling: __( 'Wrapping up the current batch before stopping.', 'wpmudev-plugin-test' ),
	completed: __( 'Maintenance scan finished successfully.', 'wpmudev-plugin-test' ),
	cancelled: __( 'Scan cancelled by user.', 'wpmudev-plugin-test' ),
	failed: __( 'Something went wrong while scanning.', 'wpmudev-plugin-test' ),
};

const getDefaultSelection = ( available = [], preferred = [] ) => {
	if ( preferred.length ) {
		return preferred;
	}

	if ( available.length ) {
		return [ available[ 0 ].slug ];
	}

	return [];
};

const emptyJob = {
	status: '',
	total: 0,
	processed: 0,
	message: '',
};

const MAX_CRON_SLOTS = 6;

const sanitizeTimeInput = ( value = '00:00' ) => {
	if ( typeof value !== 'string' ) {
		return '00:00';
	}

	const match = value.match( /^(\d{1,2}):(\d{2})$/ );

	if ( ! match ) {
		return '00:00';
	}

	const hours = Math.min( 23, Math.max( 0, parseInt( match[1], 10 ) ) )
		.toString()
		.padStart( 2, '0' );
	const minutes = Math.min( 59, Math.max( 0, parseInt( match[2], 10 ) ) )
		.toString()
		.padStart( 2, '0' );

	return `${ hours }:${ minutes }`;
};

const getInitialCronTimes = ( status = {} ) => {
	const settings = status.settings || {};

	if ( Array.isArray( settings.cron_times ) && settings.cron_times.length ) {
		return settings.cron_times.map( sanitizeTimeInput );
	}

	if ( settings.cron_time ) {
		return [ sanitizeTimeInput( settings.cron_time ) ];
	}

	return [ '00:00' ];
};

const getNextSuggestedTime = ( times = [] ) => {
	const last = times.length ? times[ times.length - 1 ] : '00:00';
	const [ hour = '00', minute = '00' ] = last.split( ':' );
	const totalMinutes = ( ( parseInt( hour, 10 ) || 0 ) * 60 + ( parseInt( minute, 10 ) || 0 ) + 240 ) % ( 24 * 60 );
	const nextHour = Math.floor( totalMinutes / 60 )
		.toString()
		.padStart( 2, '0' );
	const nextMinute = ( totalMinutes % 60 ).toString().padStart( 2, '0' );

	return `${ nextHour }:${ nextMinute }`;
};

const formatCronSummary = ( times = [] ) => {
	if ( ! times.length ) {
		return '00:00';
	}

	return times.join( ', ' );
};

const arraysMatch = ( first = [], second = [] ) => {
	if ( first.length !== second.length ) {
		return false;
	}

	for ( let index = 0; index < first.length; index += 1 ) {
		if ( first[ index ] !== second[ index ] ) {
			return false;
		}
	}

	return true;
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
		cancelling: __( 'Cancelling', 'wpmudev-plugin-test' ),
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
	const [ job, setJob ] = useState( initialStatus.job || emptyJob );
	const [ availableTypes, setAvailableTypes ] = useState( initialStatus.postTypes || [] );
	const [ lastRun, setLastRun ] = useState( initialStatus.last_run || null );
	const [ notice, setNotice ] = useState( null );
	const [ isStarting, setIsStarting ] = useState( false );
	const [ isRefreshing, setIsRefreshing ] = useState( false );
	const [ isResetting, setIsResetting ] = useState( false );
	const [ isSavingSettings, setIsSavingSettings ] = useState( false );
	const [ hasPendingSettings, setHasPendingSettings ] = useState( false );

	const initialSelection = getDefaultSelection(
		initialStatus.postTypes || [],
		initialStatus.settings?.post_types || []
	);
	const [ selectedTypes, setSelectedTypes ] = useState( initialSelection );
	const [ cronEnabled, setCronEnabled ] = useState( initialStatus.settings?.cron_enabled ?? true );
	const [ cronTimes, setCronTimes ] = useState( getInitialCronTimes( initialStatus ) );

	const saveTimerRef = useRef( null );
	const hydratedRef = useRef( false );
	const skipAutoSaveRef = useRef( false );

	const jobActive = job && [ 'pending', 'running', 'cancelling' ].includes( job.status );
	const canCancel = job && [ 'pending', 'running' ].includes( job.status );
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

	const persistSettings = useCallback(
		async ( { showFeedback = false } = {} ) => {
			if ( ! config.endpoints?.settings ) {
				if ( showFeedback ) {
					showNotice( __( 'Settings endpoint unavailable.', 'wpmudev-plugin-test' ), 'error' );
				}
				setHasPendingSettings( false );
				return false;
			}

			setIsSavingSettings( true );

			try {
				const response = await apiFetch( {
					path: `/${ config.endpoints.settings }`,
					method: 'POST',
					data: {
						post_types: selectedTypes,
						cron_enabled: cronEnabled,
						cron_times: cronTimes,
						cron_time: cronTimes[0],
					},
				} );

				if ( response?.success ) {
					setHasPendingSettings( false );

					if ( response.settings ) {
						const settings = response.settings;

						skipAutoSaveRef.current = true;

						setCronEnabled( settings.cron_enabled ?? true );
						const nextCronTimes =
							Array.isArray( settings.cron_times ) && settings.cron_times.length
								? settings.cron_times
								: [ settings.cron_time || '00:00' ];
						setCronTimes( nextCronTimes.map( sanitizeTimeInput ) );

						if (
							Array.isArray( settings.post_types ) &&
							! arraysMatch( settings.post_types, selectedTypes )
						) {
							setSelectedTypes( settings.post_types );
						}
					}

					if ( showFeedback ) {
						showNotice( __( 'Automation settings saved.', 'wpmudev-plugin-test' ) );
					}

					return true;
				}

				throw new Error( response?.message || __( 'Unable to save settings.', 'wpmudev-plugin-test' ) );
			} catch ( error ) {
				if ( showFeedback ) {
					showNotice( error?.message || __( 'Unable to save settings.', 'wpmudev-plugin-test' ), 'error' );
				}
				setHasPendingSettings( true );
				return false;
			} finally {
				setIsSavingSettings( false );
			}
		},
		[ cronEnabled, cronTimes, selectedTypes, showNotice ]
	);

	const fetchStatus = useCallback(
		async ( silent = false ) => {
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

					if ( status.settings ) {
						skipAutoSaveRef.current = true;
						setCronEnabled( status.settings.cron_enabled ?? true );
						const latestCronTimes =
							Array.isArray( status.settings.cron_times ) && status.settings.cron_times.length
								? status.settings.cron_times
								: [ status.settings.cron_time ?? '00:00' ];
						setCronTimes( latestCronTimes.map( sanitizeTimeInput ) );

						if ( ! silent && Array.isArray( status.settings.post_types ) ) {
							setSelectedTypes( status.settings.post_types );
						}
					}
				}
			} catch ( error ) {
				if ( ! silent ) {
					showNotice( error?.message || __( 'Unable to fetch status.', 'wpmudev-plugin-test' ), 'error' );
				}
			} finally {
				if ( ! silent ) {
					setIsRefreshing( false );
				}
			}
		},
		[ showNotice ]
	);

	useEffect( () => {
		fetchStatus( true );
	}, [ fetchStatus ] );

	useEffect( () => {
		const interval = jobActive ? 1500 : 15000;
		const timer = setInterval( () => fetchStatus( true ), interval );

		return () => clearInterval( timer );
	}, [ jobActive, fetchStatus ] );

	useEffect( () => {
		if ( jobActive ) {
			fetchStatus( true );
		}
	}, [ jobActive, fetchStatus ] );

	useEffect( () => {
		if ( ! config.endpoints?.settings ) {
			return;
		}

		if ( skipAutoSaveRef.current ) {
			skipAutoSaveRef.current = false;
			return;
		}

		if ( ! hydratedRef.current ) {
			hydratedRef.current = true;
			return;
		}

		setHasPendingSettings( true );

		if ( saveTimerRef.current ) {
			clearTimeout( saveTimerRef.current );
		}

		saveTimerRef.current = setTimeout( () => {
			persistSettings().finally( () => {
				saveTimerRef.current = null;
			} );
		}, 800 );
	}, [ selectedTypes, cronEnabled, cronTimes, persistSettings ] );

	useEffect( () => {
		return () => {
			if ( saveTimerRef.current ) {
				clearTimeout( saveTimerRef.current );
			}
		};
	}, [] );

	useEffect( () => {
		if ( ! availableTypes.length ) {
			return;
		}

		const allowed = new Set( availableTypes.map( ( type ) => type.slug ) );

		setSelectedTypes( ( current ) => {
			if ( ! current.length ) {
				return current;
			}

			const filtered = current.filter( ( slug ) => allowed.has( slug ) );

			if ( filtered.length === current.length && arraysMatch( filtered, current ) ) {
				return current;
			}

			const nextSelection = filtered.length ? filtered : getDefaultSelection( availableTypes );

			if ( nextSelection.length ) {
				skipAutoSaveRef.current = true;
			}

			return nextSelection;
		} );
	}, [ availableTypes ] );

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

	const handleCronTimeChange = ( index, value ) => {
		if ( ! cronEnabled ) {
			return;
		}

		setCronTimes( ( current ) => {
			const next = [ ...current ];
			next[ index ] = sanitizeTimeInput( value || '00:00' );
			return next;
		} );
	};

	const addCronTimeRow = () => {
		if ( ! cronEnabled || cronTimes.length >= MAX_CRON_SLOTS ) {
			return;
		}

		setCronTimes( ( current ) => [ ...current, getNextSuggestedTime( current ) ] );
	};

	const removeCronTimeRow = ( index ) => {
		setCronTimes( ( current ) => {
			if ( current.length === 1 ) {
				return current;
			}

			return current.filter( ( _, i ) => i !== index );
		} );
	};

	const handleMultiRunToggle = ( value ) => {
		if ( ! cronEnabled ) {
			return;
		}

		if ( value ) {
			setCronTimes( ( current ) => {
				if ( current.length > 1 ) {
					return current;
				}

				return [ current[0], getNextSuggestedTime( current ) ];
			} );

			return;
		}

		setCronTimes( ( current ) => ( current.length ? [ current[0] ] : [ '00:00' ] ) );
	};

	const handleManualSave = async () => {
		if ( saveTimerRef.current ) {
			clearTimeout( saveTimerRef.current );
			saveTimerRef.current = null;
		}

		await persistSettings( { showFeedback: true } );
	};

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
				await fetchStatus( false );
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
				showNotice( __( 'Cancellation requested. We will stop after the current batch.', 'wpmudev-plugin-test' ) );
				fetchStatus( true );
				return;
			}

			throw new Error( response?.message || __( 'Unable to cancel the scan.', 'wpmudev-plugin-test' ) );
		} catch ( error ) {
			showNotice( error?.message || __( 'Unable to cancel the scan.', 'wpmudev-plugin-test' ), 'error' );
		}
	};

	const handleResetJob = async () => {
		try {
			setIsResetting( true );
			const response = await apiFetch( {
				path: `/${ config.endpoints.reset }`,
				method: 'POST',
			} );

			if ( response?.success ) {
				showNotice( __( 'Scan state reset. You can start a new run now.', 'wpmudev-plugin-test' ) );
				fetchStatus( false );
				return;
			}

			throw new Error( response?.message || __( 'Unable to reset the scan state.', 'wpmudev-plugin-test' ) );
		} catch ( error ) {
			showNotice( error?.message || __( 'Unable to reset the scan state.', 'wpmudev-plugin-test' ), 'error' );
		} finally {
			setIsResetting( false );
		}
	};

	const formatTimestamp = ( timestamp ) => {
		if ( ! timestamp ) {
			return __( 'Not available', 'wpmudev-plugin-test' );
		}

		return new Date( timestamp * 1000 ).toLocaleString();
	};

	const lastRunDisplay = lastRun?.completed_at
		? formatTimestamp( lastRun.completed_at )
		: __( 'Not yet run', 'wpmudev-plugin-test' );
	const postTypesLabel = selectedTypes.length
		? selectedTypes.join( ', ' )
		: __( 'No post types selected', 'wpmudev-plugin-test' );
	const jobHelper = job?.message || __( 'Ready for your next scan.', 'wpmudev-plugin-test' );
	const cliCommand = selectedTypes.length
		? `wp wpmudev posts-maintenance scan --post_types=${ selectedTypes.join( ',' ) }`
		: 'wp wpmudev posts-maintenance scan --post_types=post,page';
	const isMultiRun = cronTimes.length > 1;
	const canAddCronSlot = cronTimes.length < MAX_CRON_SLOTS;
	const cronSummaryTimes = formatCronSummary( cronTimes );
	const cronSummary = cronEnabled
		? sprintf( __( 'Runs daily at %s (site time).', 'wpmudev-plugin-test' ), cronSummaryTimes )
		: __( 'Automation paused until you enable it.', 'wpmudev-plugin-test' );
	const saveIndicatorText = hasPendingSettings
		? __( 'Saving preferences...', 'wpmudev-plugin-test' )
		: __( 'Preferences synced to server.', 'wpmudev-plugin-test' );

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
						<span className="pm-hero__divider">|</span>
						<span>{ sprintf( __( 'Last run: %s', 'wpmudev-plugin-test' ), lastRunDisplay ) }</span>
					</div>
				</div>
				<div className="pm-hero__actions">
					<div className="pm-hero__actions-buttons">
						<Button
							type="button"
							className="pm-btn pm-btn--primary"
							onClick={ handleStart }
							isBusy={ isStarting }
							disabled={ isStarting || jobActive || ! selectedTypes.length }
						>
							{ jobActive ? __( 'Scan In Progress...', 'wpmudev-plugin-test' ) : __( 'Scan Posts Now', 'wpmudev-plugin-test' ) }
						</Button>
						<Button
							type="button"
							className="pm-btn pm-btn--ghost pm-btn--ghost-invert"
							onClick={ () => fetchStatus( false ) }
							isBusy={ isRefreshing }
						>
							{ isRefreshing ? __( 'Syncing...', 'wpmudev-plugin-test' ) : __( 'Sync Status Now', 'wpmudev-plugin-test' ) }
						</Button>
						{ canCancel && (
							<Button type="button" onClick={ handleCancel } className="pm-btn pm-btn--outline">
								{ __( 'Cancel Scan', 'wpmudev-plugin-test' ) }
							</Button>
						) }
						<Button
							type="button"
							className="pm-btn pm-btn--ghost pm-btn--ghost-alt"
							onClick={ handleResetJob }
							isBusy={ isResetting }
							disabled={ isResetting }
						>
							{ __( 'Reset Scan State', 'wpmudev-plugin-test' ) }
						</Button>
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
					value={ cronEnabled ? __( 'Enabled', 'wpmudev-plugin-test' ) : __( 'Disabled', 'wpmudev-plugin-test' ) }
					helper={ cronSummary }
				/>
				<StatCard
					label={ __( 'Last completed scan', 'wpmudev-plugin-test' ) }
					value={ lastRunDisplay }
					helper={ __( 'Stored in "Last Run Summary"', 'wpmudev-plugin-test' ) }
				/>
			</div>

			{ notice && (
				<div className="pm-toast-stack" aria-live="polite">
					<div className={ `pm-toast pm-toast--${ notice.type || 'info' }` }>
						<div className="pm-toast__body">
							<span className="pm-toast__label">
								{ notice.type === 'error'
									? __( 'Heads up', 'wpmudev-plugin-test' )
									: __( 'All good', 'wpmudev-plugin-test' ) }
							</span>
							<p className="pm-toast__message">{ notice.message }</p>
						</div>
						<button
							type="button"
							className="pm-toast__dismiss"
							onClick={ () => setNotice( null ) }
							aria-label={ __( 'Dismiss notification', 'wpmudev-plugin-test' ) }
						>
							<span aria-hidden="true">×</span>
						</button>
					</div>
				</div>
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
							type="button"
							className="pm-btn pm-btn--secondary"
							onClick={ () => fetchStatus( false ) }
							isBusy={ isRefreshing }
						>
							{ isRefreshing ? __( 'Syncing...', 'wpmudev-plugin-test' ) : __( 'Sync Filters From Server', 'wpmudev-plugin-test' ) }
						</Button>
					</div>
				</div>
			</div>

			<div className="pm-progress-panel pm-progress-panel--wide">
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
				<div
					className="pm-progressbar-track"
					role="progressbar"
					aria-valuenow={ percentComplete }
					aria-valuemin="0"
					aria-valuemax="100"
				>
					<div
						className={ `pm-progressbar-fill${ jobActive ? ' is-animated' : '' }` }
						style={ { width: `${ percentComplete }%` } }
					/>
				</div>
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

			<div className="sui-box pm-automation-box">
				<div className="sui-box-header">
					<h2 className="sui-box-title">{ __( 'Automation Schedule', 'wpmudev-plugin-test' ) }</h2>
				</div>
				<div className="sui-box-body">
					<div className="pm-automation-grid">
						<ToggleControl
							checked={ cronEnabled }
							label={ __( 'Enable daily WP-Cron task', 'wpmudev-plugin-test' ) }
							help={ __( 'Keeps the maintenance scan running once per day without manual input.', 'wpmudev-plugin-test' ) }
							onChange={ ( value ) => setCronEnabled( value ) }
						/>
						<ToggleControl
							checked={ isMultiRun }
							label={ __( 'Run multiple times per day', 'wpmudev-plugin-test' ) }
							help={ __( 'Schedule up to six daily executions at specific times.', 'wpmudev-plugin-test' ) }
							onChange={ handleMultiRunToggle }
							disabled={ ! cronEnabled }
						/>
					</div>
					<div className={ `pm-cron-times ${ cronEnabled ? '' : 'is-disabled' }` }>
						{ cronTimes.map( ( time, index ) => (
							<div className="pm-cron-time-row" key={ `cron-time-${ index }-${ time }` }>
								<TextControl
									label={
										index === 0
											? __( 'Run time (site timezone)', 'wpmudev-plugin-test' )
											: __( 'Additional run time', 'wpmudev-plugin-test' )
									}
									type="time"
									value={ time }
									onChange={ ( value ) => handleCronTimeChange( index, value ) }
									disabled={ ! cronEnabled }
									step="60"
								/>
								{ isMultiRun && cronTimes.length > 1 && (
									<Button
										type="button"
										className="pm-btn pm-btn--ghost pm-cron-remove"
										onClick={ () => removeCronTimeRow( index ) }
										disabled={ ! cronEnabled }
									>
										{ __( 'Remove', 'wpmudev-plugin-test' ) }
									</Button>
								) }
							</div>
						) ) }
						{ cronEnabled && isMultiRun && (
							<div className="pm-cron-actions">
								<Button
									type="button"
									className="pm-btn pm-btn--ghost pm-cron-add"
									onClick={ addCronTimeRow }
									disabled={ ! canAddCronSlot }
								>
									{ __( 'Add another run time', 'wpmudev-plugin-test' ) }
								</Button>
								{ ! canAddCronSlot && (
									<p className="sui-description pm-cron-limit">
										{ __( 'Maximum of six daily runs reached.', 'wpmudev-plugin-test' ) }
									</p>
								) }
							</div>
						) }
					</div>
					<p className="sui-description">
						{ cronEnabled
							? __( 'Each time listed above is queued automatically with WP-Cron.', 'wpmudev-plugin-test' )
							: __( 'Daily automation is paused until you enable it again.', 'wpmudev-plugin-test' ) }
					</p>
				</div>
				<div className="sui-box-footer">
					<div className="sui-actions-left">
						<p className={ `pm-save-indicator ${ hasPendingSettings ? 'is-dirty' : '' }` }>
							{ saveIndicatorText }
						</p>
					</div>
					<div className="sui-actions-right">
						<Button
							type="button"
							className="pm-btn pm-btn--secondary"
							onClick={ handleManualSave }
							isBusy={ isSavingSettings }
							disabled={ isSavingSettings || ! config.endpoints?.settings }
						>
							{ __( 'Save Automation Settings', 'wpmudev-plugin-test' ) }
						</Button>
					</div>
				</div>
			</div>

			<div className="pm-history-grid pm-history-grid--single">
				<div className="sui-box pm-cli-box">
					<div className="sui-box-header">
						<h2 className="sui-box-title">{ __( 'WP-CLI Shortcut', 'wpmudev-plugin-test' ) }</h2>
					</div>
					<div className="sui-box-body">
						<p>{ __( 'Run the same maintenance routine directly from the terminal. Perfect for cron jobs or CI pipelines.', 'wpmudev-plugin-test' ) }</p>
						<pre>
							<code>{ cliCommand }</code>
						</pre>
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
