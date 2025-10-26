import {
	createRoot,
	render,
	StrictMode,
	useEffect,
	useMemo,
	useState,
	createInterpolateElement,
} from '@wordpress/element';
import { Button, TextControl, Spinner, Notice } from '@wordpress/components';
import apiFetch, { createNonceMiddleware } from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

import './scss/style.scss';

const driveConfig = window.wpmudevDriveTest || {};

if ( driveConfig?.nonce && apiFetch?.use ) {
	apiFetch.use( createNonceMiddleware( driveConfig.nonce ) );
}

const ensureLeadingSlash = ( endpoint = '' ) => {
	if ( ! endpoint ) {
		return '';
	}

	return endpoint.startsWith( '/' ) ? endpoint : `/${ endpoint }`;
};

const getRestUrl = ( endpoint, query = '' ) => {
	const base = ensureLeadingSlash( endpoint );
	if ( ! query ) {
		return base;
	}

	return `${ base }?${ query }`;
};

const absoluteRestUrl = ( endpoint ) => {
	const root = window?.wpApiSettings?.root || '';
	return `${ root.replace( /\/$/, '' ) }${ ensureLeadingSlash( endpoint ) }`;
};

const formatBytes = ( bytes ) => {
	if ( ! bytes || Number.isNaN( bytes ) ) {
		return __( '—', 'wpmudev-plugin-test' );
	}

	const size = Number( bytes );
	if ( size === 0 ) {
		return __( '0 B', 'wpmudev-plugin-test' );
	}

	const k = 1024;
	const dm = 1;
	const sizes = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
	const i = Math.floor( Math.log( size ) / Math.log( k ) );

	return `${ parseFloat( ( size / Math.pow( k, i ) ).toFixed( dm ) ) } ${ sizes[ i ] }`;
};

const getDefaultNotice = () => ( { message: '', type: '' } );

const DrivePage = () => {
	const [ hasCredentials, setHasCredentials ] = useState( Boolean( driveConfig.hasCredentials ) );
	const [ showCredentials, setShowCredentials ] = useState( ! driveConfig.hasCredentials );
	const [ credentials, setCredentials ] = useState( {
		clientId: '',
		clientSecret: '',
	} );
	const [ notice, setNotice ] = useState( getDefaultNotice() );
	const [ isAuthenticated, setIsAuthenticated ] = useState( Boolean( driveConfig.authStatus ) );
	const [ savingCredentials, setSavingCredentials ] = useState( false );
	const [ authInProgress, setAuthInProgress ] = useState( false );
	const [ filesLoading, setFilesLoading ] = useState( false );
	const [ files, setFiles ] = useState( [] );
	const [ nextPageToken, setNextPageToken ] = useState( null );
	const [ uploadFile, setUploadFile ] = useState( null );
	const [ uploadProgress, setUploadProgress ] = useState( null );
	const [ uploading, setUploading ] = useState( false );
	const [ folderName, setFolderName ] = useState( '' );
	const [ creatingFolder, setCreatingFolder ] = useState( false );
	const [ fileInputKey, setFileInputKey ] = useState( Date.now() );

	const scopes = useMemo( () => driveConfig.scopes || [
		'https://www.googleapis.com/auth/drive.file',
		'https://www.googleapis.com/auth/drive.readonly',
	], [] );

	const maxUploadSize = Number( driveConfig.maxUploadSize || window?.wp_max_upload_size || 0 );

	const clearNotice = () => setNotice( getDefaultNotice() );
	const showNotice = ( message, type = 'success' ) => {
		setNotice( { message, type } );
	};

	useEffect( () => {
		if ( notice.message ) {
			const timeout = setTimeout( clearNotice, 5000 );
			return () => clearTimeout( timeout );
		}
		return undefined;
	}, [ notice ] );

	useEffect( () => {
		const params = new URLSearchParams( window.location.search );
		const auth = params.get( 'auth' );
		const message = params.get( 'message' );
		if ( auth ) {
			params.delete( 'auth' );
			params.delete( 'message' );
			const newUrl = `${ window.location.pathname }${ params.toString() ? `?${ params }` : '' }`;
			window.history.replaceState( {}, document.title, newUrl );
		}

		if ( auth === 'success' ) {
			setIsAuthenticated( true );
			showNotice( __( 'Google Drive authentication completed successfully.', 'wpmudev-plugin-test' ) );
		} else if ( auth === 'error' ) {
			showNotice(
				message || __( 'Google Drive authentication failed. Please try again.', 'wpmudev-plugin-test' ),
				'error'
			);
		}
	}, [] );

	useEffect( () => {
		if ( isAuthenticated ) {
			loadFiles();
		}
	}, [ isAuthenticated ] );

	const handleFieldChange = ( key, value ) => {
		setCredentials( ( prev ) => ( {
			...prev,
			[ key ]: value,
		} ) );
	};

	const handleSaveCredentials = async () => {
		if ( ! credentials.clientId.trim() || ! credentials.clientSecret.trim() ) {
			showNotice( __( 'Both Client ID and Client Secret are required.', 'wpmudev-plugin-test' ), 'error' );
			return;
		}

		setSavingCredentials( true );
		try {
			const response = await apiFetch( {
				path: getRestUrl( driveConfig.restEndpointSave ),
				method: 'POST',
				data: {
					client_id: credentials.clientId.trim(),
					client_secret: credentials.clientSecret.trim(),
				},
			} );

			if ( ! response?.success ) {
				throw new Error( response?.message || __( 'Unable to save credentials.', 'wpmudev-plugin-test' ) );
			}

			showNotice( __( 'Credentials saved successfully.', 'wpmudev-plugin-test' ) );
			setHasCredentials( true );
			setShowCredentials( false );
			setCredentials( { clientId: '', clientSecret: '' } );
		} catch ( error ) {
			showNotice( error?.message || __( 'Unable to save credentials.', 'wpmudev-plugin-test' ), 'error' );
		} finally {
			setSavingCredentials( false );
		}
	};

	const handleAuth = async () => {
		if ( ! hasCredentials ) {
			showNotice( __( 'Please save your Google credentials before authenticating.', 'wpmudev-plugin-test' ), 'error' );
			return;
		}

		setAuthInProgress( true );
		try {
			const response = await apiFetch( {
				path: getRestUrl( driveConfig.restEndpointAuth ),
				method: 'POST',
			} );

			if ( ! response?.success || ! response?.authUrl ) {
				throw new Error( response?.message || __( 'Unable to start authentication.', 'wpmudev-plugin-test' ) );
			}

			window.location.href = response.authUrl;
		} catch ( error ) {
			setAuthInProgress( false );
			showNotice( error?.message || __( 'Unable to start authentication.', 'wpmudev-plugin-test' ), 'error' );
		}
	};

	const loadFiles = async ( append = false, pageToken = '' ) => {
		if ( ! isAuthenticated ) {
			return;
		}

		setFilesLoading( true );
		try {
			const response = await apiFetch( {
				path: getRestUrl(
					driveConfig.restEndpointFiles,
					pageToken ? `page_token=${ encodeURIComponent( pageToken ) }` : ''
				),
				method: 'GET',
			} );

			if ( ! response?.success ) {
				throw new Error( response?.message || __( 'Unable to load Drive files.', 'wpmudev-plugin-test' ) );
			}

			const list = response.files || [];
			setFiles( ( current ) => ( append ? [ ...current, ...list ] : list ) );
			setNextPageToken( response.nextPageToken || null );
		} catch ( error ) {
			showNotice( error?.message || __( 'Unable to load Drive files.', 'wpmudev-plugin-test' ), 'error' );
		} finally {
			setFilesLoading( false );
		}
	};

	const handleUpload = () => {
		if ( ! uploadFile ) {
			showNotice( __( 'Please choose a file to upload.', 'wpmudev-plugin-test' ), 'error' );
			return;
		}

		if ( maxUploadSize && uploadFile.size > maxUploadSize ) {
			showNotice(
				sprintf(
					__( 'File exceeds the maximum upload size of %s.', 'wpmudev-plugin-test' ),
					formatBytes( maxUploadSize )
				),
				'error'
			);
			return;
		}

		setUploading( true );
		setUploadProgress( 0 );

		const formData = new FormData();
		formData.append( 'file', uploadFile );

		const xhr = new XMLHttpRequest();
		xhr.open( 'POST', absoluteRestUrl( driveConfig.restEndpointUpload ) );
		xhr.setRequestHeader( 'X-WP-Nonce', driveConfig.nonce );

		xhr.upload.onprogress = ( event ) => {
			if ( event.lengthComputable ) {
				const percent = Math.round( ( event.loaded / event.total ) * 100 );
				setUploadProgress( percent );
			}
		};

		const resetUploader = () => {
			setUploading( false );
			setUploadProgress( null );
			setUploadFile( null );
			setFileInputKey( Date.now() );
		};

		xhr.onerror = () => {
			resetUploader();
			showNotice( __( 'Upload failed. Please try again.', 'wpmudev-plugin-test' ), 'error' );
		};

		xhr.onload = () => {
			let response;
			try {
				response = JSON.parse( xhr.responseText );
			} catch ( e ) {
				// Do nothing, response handled below.
			}

			resetUploader();

			if ( xhr.status >= 200 && xhr.status < 300 && response?.success ) {
				showNotice( __( 'File uploaded successfully.', 'wpmudev-plugin-test' ) );
				loadFiles();
				return;
			}

			showNotice(
				response?.message || __( 'Upload failed. Please try again.', 'wpmudev-plugin-test' ),
				'error'
			);
		};

		xhr.send( formData );
	};

	const handleDownload = async ( fileId, fileName ) => {
		try {
			const response = await apiFetch( {
				path: getRestUrl(
					driveConfig.restEndpointDownload,
					`file_id=${ encodeURIComponent( fileId ) }`
				),
				method: 'GET',
			} );

			if ( ! response?.success || ! response?.content ) {
				throw new Error( response?.message || __( 'Unable to download the file.', 'wpmudev-plugin-test' ) );
			}

			const byteCharacters = atob( response.content );
			const byteNumbers = new Array( byteCharacters.length );
			for ( let i = 0; i < byteCharacters.length; i += 1 ) {
				byteNumbers[ i ] = byteCharacters.charCodeAt( i );
			}

			const blob = new Blob( [ new Uint8Array( byteNumbers ) ], {
				type: response.mimeType || 'application/octet-stream',
			} );

			const url = window.URL.createObjectURL( blob );
			const link = document.createElement( 'a' );
			link.href = url;
			link.download = fileName || response.filename || 'drive-file';
			document.body.appendChild( link );
			link.click();
			document.body.removeChild( link );
			window.URL.revokeObjectURL( url );
		} catch ( error ) {
			showNotice( error?.message || __( 'Unable to download the file.', 'wpmudev-plugin-test' ), 'error' );
		}
	};

	const handleCreateFolder = async () => {
		if ( ! folderName.trim() ) {
			showNotice( __( 'Folder name cannot be empty.', 'wpmudev-plugin-test' ), 'error' );
			return;
		}

		setCreatingFolder( true );
		try {
			const response = await apiFetch( {
				path: getRestUrl( driveConfig.restEndpointCreate ),
				method: 'POST',
				data: {
					name: folderName.trim(),
				},
			} );

			if ( ! response?.success ) {
				throw new Error( response?.message || __( 'Unable to create folder.', 'wpmudev-plugin-test' ) );
			}

			showNotice( __( 'Folder created successfully.', 'wpmudev-plugin-test' ) );
			setFolderName( '' );
			loadFiles();
		} catch ( error ) {
			showNotice( error?.message || __( 'Unable to create folder.', 'wpmudev-plugin-test' ), 'error' );
		} finally {
			setCreatingFolder( false );
		}
	};

	const renderDriveActions = () => (
		<div className="sui-box">
			<div className="sui-box-header">
				<h2 className="sui-box-title">{ __( 'Google Drive Actions', 'wpmudev-plugin-test' ) }</h2>
			</div>
			<div className="sui-box-body">
				<p className="sui-description">
					{ __( 'Authenticate to upload files, create folders, and browse your Drive contents.', 'wpmudev-plugin-test' ) }
				</p>
				<Button
					variant="primary"
					onClick={ handleAuth }
					isBusy={ authInProgress }
					disabled={ authInProgress }
				>
					{ authInProgress ? __( 'Redirecting…', 'wpmudev-plugin-test' ) : __( 'Authenticate with Google Drive', 'wpmudev-plugin-test' ) }
				</Button>
			</div>
		</div>
	);

	const renderFiles = () => {
		if ( filesLoading && ! files.length ) {
			return (
				<div className="drive-loading">
					<Spinner />
					<p>{ __( 'Loading files…', 'wpmudev-plugin-test' ) }</p>
				</div>
			);
		}

		if ( ! files.length ) {
			return (
				<div className="sui-box-settings-row">
					<p>{ __( 'No files found in your Drive yet. Upload a file or refresh to try again.', 'wpmudev-plugin-test' ) }</p>
				</div>
			);
		}

		return (
			<>
				<div className="drive-files-grid">
					{ files.map( ( file ) => {
						const isFolder = file.mimeType === 'application/vnd.google-apps.folder';
						return (
							<div key={ file.id } className="drive-file-item">
								<div className="file-info">
									<strong>{ file.name }</strong>
									<small>
										{ sprintf(
											__( '%1$s • %2$s', 'wpmudev-plugin-test' ),
											isFolder ? __( 'Folder', 'wpmudev-plugin-test' ) : __( 'File', 'wpmudev-plugin-test' ),
											file.modifiedTime
												? new Date( file.modifiedTime ).toLocaleString()
												: __( 'Unknown date', 'wpmudev-plugin-test' )
										) }
									</small>
									{ ! isFolder && (
										<small>{ sprintf( __( 'Size: %s', 'wpmudev-plugin-test' ), formatBytes( file.size ) ) }</small>
									) }
								</div>
								<div className="file-actions">
									{ ! isFolder && (
										<Button
											variant="secondary"
											size="small"
											onClick={ () => handleDownload( file.id, file.name ) }
										>
											{ __( 'Download', 'wpmudev-plugin-test' ) }
										</Button>
									) }
									{ file.webViewLink && (
										<Button
											variant="link"
											size="small"
											target="_blank"
											href={ file.webViewLink }
										>
											{ __( 'View in Drive', 'wpmudev-plugin-test' ) }
										</Button>
									) }
								</div>
							</div>
						);
					} ) }
				</div>
				{ nextPageToken && (
					<div className="sui-box-settings-row">
						<Button
							variant="secondary"
							onClick={ () => loadFiles( true, nextPageToken ) }
							isBusy={ filesLoading }
						>
							{ __( 'Load More Files', 'wpmudev-plugin-test' ) }
						</Button>
					</div>
				) }
			</>
		);
	};

	return (
		<>
			<div className="sui-header">
				<h1 className="sui-header-title">{ __( 'Google Drive Test', 'wpmudev-plugin-test' ) }</h1>
				<p className="sui-description">
					{ __( 'Manage Google Drive credentials, authenticate, and test Drive file operations.', 'wpmudev-plugin-test' ) }
				</p>
			</div>

			{ notice.message && (
				<Notice status={ notice.type } isDismissible onRemove={ clearNotice }>
					{ notice.message }
				</Notice>
			) }

			<div className="sui-box">
				<div className="sui-box-header">
					<h2 className="sui-box-title">{ __( 'Google API Credentials', 'wpmudev-plugin-test' ) }</h2>
					<div className="sui-actions-right">
						{ hasCredentials && (
							<Button variant="link" onClick={ () => setShowCredentials( ( prev ) => ! prev ) }>
								{ showCredentials
									? __( 'Hide form', 'wpmudev-plugin-test' )
									: __( 'Edit credentials', 'wpmudev-plugin-test' ) }
							</Button>
						) }
					</div>
				</div>
				{ showCredentials ? (
					<>
						<div className="sui-box-body">
							<div className="sui-box-settings-row">
								<TextControl
									label={ __( 'Client ID', 'wpmudev-plugin-test' ) }
									help={
										createInterpolateElement(
											__( 'Retrieve the Client ID in the <a>Google Cloud Console</a>. Enable the Google Drive API for your project.', 'wpmudev-plugin-test' ),
											{
												a: <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noreferrer" />,
											}
										)
									}
									value={ credentials.clientId }
									onChange={ ( value ) => handleFieldChange( 'clientId', value ) }
								/>
							</div>
							<div className="sui-box-settings-row">
								<TextControl
									type="password"
									label={ __( 'Client Secret', 'wpmudev-plugin-test' ) }
									help={
										createInterpolateElement(
											__( 'Retrieve the Client Secret alongside your OAuth credentials in <a>Google Cloud Console</a>.', 'wpmudev-plugin-test' ),
											{
												a: <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noreferrer" />,
											}
										)
									}
									value={ credentials.clientSecret }
									onChange={ ( value ) => handleFieldChange( 'clientSecret', value ) }
								/>
							</div>
							<div className="sui-box-settings-row">
								<p>
									<strong>{ __( 'Authorized redirect URI:', 'wpmudev-plugin-test' ) }</strong>
								</p>
								<p>
									<code>{ driveConfig.redirectUri }</code>
								</p>
							</div>
							<div className="sui-box-settings-row">
								<p>
									<strong>{ __( 'Required OAuth scopes:', 'wpmudev-plugin-test' ) }</strong>
								</p>
								<ul>
									{ scopes.map( ( scope ) => (
										<li key={ scope }>{ scope }</li>
									) ) }
								</ul>
							</div>
						</div>
						<div className="sui-box-footer">
							<div className="sui-actions-right">
								<Button
									variant="primary"
									onClick={ handleSaveCredentials }
									isBusy={ savingCredentials }
									disabled={ savingCredentials }
								>
									{ savingCredentials ? __( 'Saving…', 'wpmudev-plugin-test' ) : __( 'Save Credentials', 'wpmudev-plugin-test' ) }
								</Button>
							</div>
						</div>
					</>
				) : (
					<div className="sui-box-body">
						<p>{ __( 'Credentials are stored securely. Use “Edit credentials” to update them.', 'wpmudev-plugin-test' ) }</p>
					</div>
				) }
			</div>

			{ ! isAuthenticated && renderDriveActions() }

			{ isAuthenticated && (
				<>
					<div className="sui-box">
						<div className="sui-box-header">
							<h2 className="sui-box-title">{ __( 'Upload File to Drive', 'wpmudev-plugin-test' ) }</h2>
						</div>
						<div className="sui-box-body">
							<div className="sui-box-settings-row">
								<input
									key={ fileInputKey }
									type="file"
									className="drive-file-input"
									onChange={ ( event ) => setUploadFile( event.target.files?.[ 0 ] || null ) }
								/>
								{ uploadFile && (
									<p>
										{ sprintf(
											__( 'Selected: %1$s (%2$s)', 'wpmudev-plugin-test' ),
											uploadFile.name,
											formatBytes( uploadFile.size )
										) }
									</p>
								) }
								{ typeof uploadProgress === 'number' && (
									<p>{ sprintf( __( 'Upload progress: %d%%', 'wpmudev-plugin-test' ), uploadProgress ) }</p>
								) }
							</div>
						</div>
						<div className="sui-box-footer">
							<div className="sui-actions-right">
								<Button
									variant="primary"
									onClick={ handleUpload }
									isBusy={ uploading }
									disabled={ uploading || ! uploadFile }
								>
									{ uploading ? __( 'Uploading…', 'wpmudev-plugin-test' ) : __( 'Upload to Drive', 'wpmudev-plugin-test' ) }
								</Button>
							</div>
						</div>
					</div>

					<div className="sui-box">
						<div className="sui-box-header">
							<h2 className="sui-box-title">{ __( 'Create New Folder', 'wpmudev-plugin-test' ) }</h2>
						</div>
						<div className="sui-box-body">
							<div className="sui-box-settings-row">
								<TextControl
									label={ __( 'Folder Name', 'wpmudev-plugin-test' ) }
									value={ folderName }
									onChange={ setFolderName }
									placeholder={ __( 'Enter folder name', 'wpmudev-plugin-test' ) }
								/>
							</div>
						</div>
						<div className="sui-box-footer">
							<div className="sui-actions-right">
								<Button
									variant="secondary"
									onClick={ handleCreateFolder }
									isBusy={ creatingFolder }
									disabled={ creatingFolder || ! folderName.trim() }
								>
									{ creatingFolder ? __( 'Creating…', 'wpmudev-plugin-test' ) : __( 'Create Folder', 'wpmudev-plugin-test' ) }
								</Button>
							</div>
						</div>
					</div>

					<div className="sui-box">
						<div className="sui-box-header">
							<h2 className="sui-box-title">{ __( 'Your Drive Files', 'wpmudev-plugin-test' ) }</h2>
							<div className="sui-actions-right">
								<Button
									variant="secondary"
									onClick={ () => loadFiles( false ) }
									isBusy={ filesLoading }
								>
									{ filesLoading ? __( 'Refreshing…', 'wpmudev-plugin-test' ) : __( 'Refresh Files', 'wpmudev-plugin-test' ) }
								</Button>
							</div>
						</div>
						<div className="sui-box-body">
							{ renderFiles() }
						</div>
					</div>
				</>
			) }
		</>
	);
};

const mountNode = document.getElementById( driveConfig.dom_element_id );

if ( mountNode ) {
	const App = (
		<StrictMode>
			<DrivePage />
		</StrictMode>
	);

	if ( createRoot ) {
		createRoot( mountNode ).render( App );
	} else {
		render( App, mountNode );
	}
}
