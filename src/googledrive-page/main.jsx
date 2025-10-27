import {
	createRoot,
	render,
	StrictMode,
	useEffect,
	useMemo,
	useRef,
	useState,
	createInterpolateElement,
} from "@wordpress/element";
import apiFetch, { createNonceMiddleware } from "@wordpress/api-fetch";
import { __, sprintf } from "@wordpress/i18n";

import {
	Alert,
	Badge,
	Button,
	Card,
	CardContent,
	CardDescription,
	CardFooter,
	CardHeader,
	CardTitle,
	FormField,
	Input,
	Select,
	Spinner,
} from "../ui";

import "../styles/shadcn.scss";
import "./scss/style.scss";

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
    const rootSetting = window?.wpApiSettings?.root;
    const restPrefix = window?.wpApiSettings?.rest_url_prefix || 'wp-json';
    let root = '';

    if ( rootSetting && typeof rootSetting === 'string' ) {
        root = rootSetting.replace( /\/$/, '' );
    } else {
        const { origin, pathname } = window.location;
        const adminIndex = pathname.indexOf( '/wp-admin' );
        const basePath = adminIndex !== -1 ? pathname.substring( 0, adminIndex ) : '';
        root = `${ origin }${ basePath ? basePath.replace( /\/$/, '' ) : '' }/${ restPrefix.replace( /^\/+|\/+$/g, '' ) }`;
    }

    return `${ root }${ ensureLeadingSlash( endpoint ) }`;
};

const formatBytes = ( bytes ) => {
    if ( ! bytes || Number.isNaN( bytes ) ) {
        return __( 'Unknown size', 'wpmudev-plugin-test' );
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

const formatScopeLabel = ( scope = '' ) => scope.replace( 'https://www.googleapis.com/auth/', '' );

const StatusBadge = ( { tone = "accent", children } ) => {
	const toneMap = {
		success: "success",
		warning: "warning",
		error: "warning",
		muted: "accent",
		neutral: "accent",
	};

	const resolvedTone = toneMap[ tone ] || "accent";

	return (
		<Badge tone={ resolvedTone } className="drive-status-badge">
			{ children }
		</Badge>
	);
};

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
    const [ revokingAuth, setRevokingAuth ] = useState( false );
    const [ filesLoading, setFilesLoading ] = useState( false );
    const [ files, setFiles ] = useState( [] );
    const [ nextPageToken, setNextPageToken ] = useState( null );
    const [ uploadFile, setUploadFile ] = useState( null );
    const [ uploadProgress, setUploadProgress ] = useState( null );
    const [ uploading, setUploading ] = useState( false );
    const [ folderName, setFolderName ] = useState( '' );
    const [ creatingFolder, setCreatingFolder ] = useState( false );
    const [ fileInputKey, setFileInputKey ] = useState( Date.now() );
    const [ isDraggingOver, setIsDraggingOver ] = useState( false );
    const [ openMenuId, setOpenMenuId ] = useState( null );
    const [ folderStack, setFolderStack ] = useState( [ { id: null, name: __( 'My Drive', 'wpmudev-plugin-test' ) } ] );
    const [ searchTerm, setSearchTerm ] = useState( '' );
    const [ renameTarget, setRenameTarget ] = useState( null );
    const [ renameValue, setRenameValue ] = useState( '' );
    const [ renaming, setRenaming ] = useState( false );
	const [ deletingId, setDeletingId ] = useState( null );
	const [ sortOrder, setSortOrder ] = useState( 'desc' );
	const fileInputRef = useRef( null );
	const searchDebounceRef = useRef( null );
	const initialSearchSyncRef = useRef( true );
	const skipNextSearchRef = useRef( false );
	const initialSortSyncRef = useRef( true );
	const [ previewFailures, setPreviewFailures ] = useState( {} );
	const [ redirectCopied, setRedirectCopied ] = useState( false );
	const redirectCopyTimerRef = useRef( null );
	const viewMode = driveConfig.viewMode || 'settings';
	const isSettingsView = viewMode === 'settings';
	const isManageView = viewMode === 'manage';

    const scopes = useMemo( () => driveConfig.scopes || [
        'https://www.googleapis.com/auth/drive.file',
        'https://www.googleapis.com/auth/drive.readonly',
    ], [] );

    const maxUploadSize = Number( driveConfig.maxUploadSize || window?.wp_max_upload_size || 0 );
	const redirectUri = driveConfig.redirectUri || '';
    const uploadLimitLabel = maxUploadSize > 0
        ? formatBytes( maxUploadSize )
        : __( 'Server default', 'wpmudev-plugin-test' );
    const currentFolder = folderStack[ folderStack.length - 1 ] || folderStack[ 0 ];

    const connectionSummary = useMemo(
        () => (
            isAuthenticated
                ? {
                    tone: 'success',
                    title: __( 'Connected to Google Drive', 'wpmudev-plugin-test' ),
                    description: isManageView
                        ? __( 'You can upload files, browse folders, and trigger Drive tasks directly from WordPress.', 'wpmudev-plugin-test' )
                        : __( 'Google Drive is connected. Use the Manage Google Drive page to work with your files.', 'wpmudev-plugin-test' ),
                }
                : {
                    tone: 'muted',
                    title: __( 'Connect your Google account', 'wpmudev-plugin-test' ),
                    description: __( 'Finish Google authentication to unlock uploads, folder creation, and file browsing tools.', 'wpmudev-plugin-test' ),
                }
        ),
        [ isAuthenticated, isManageView ]
    );

    const statusCards = useMemo(
        () => ( [
            {
                id: 'credentials',
                label: __( 'API Credentials', 'wpmudev-plugin-test' ),
                value: hasCredentials ? __( 'Stored', 'wpmudev-plugin-test' ) : __( 'Missing', 'wpmudev-plugin-test' ),
                tone: hasCredentials ? 'success' : 'warning',
                helper: hasCredentials
                    ? __( 'Client ID and secret are saved securely.', 'wpmudev-plugin-test' )
                    : __( 'Add your OAuth client ID and secret to continue.', 'wpmudev-plugin-test' ),
            },
            {
                id: 'authentication',
                label: __( 'Authentication', 'wpmudev-plugin-test' ),
                value: isAuthenticated ? __( 'Active', 'wpmudev-plugin-test' ) : __( 'Not connected', 'wpmudev-plugin-test' ),
                tone: isAuthenticated ? 'success' : 'muted',
                helper: isAuthenticated
                    ? __( 'Drive actions are ready to go.', 'wpmudev-plugin-test' )
                    : __( 'Authenticate with Google to manage Drive content.', 'wpmudev-plugin-test' ),
            },
            {
                id: 'upload-limit',
                label: __( 'Upload Limit', 'wpmudev-plugin-test' ),
                value: uploadLimitLabel,
                tone: 'neutral',
                helper: __( 'Matches the maximum upload size allowed by this site.', 'wpmudev-plugin-test' ),
            },
        ] ),
        [ hasCredentials, isAuthenticated, uploadLimitLabel ]
    );

    const setupSteps = useMemo(
        () => ( [
            __( 'Create OAuth client credentials in Google Cloud Console using the Web application type.', 'wpmudev-plugin-test' ),
            __( 'Add the redirect URI below to the list of authorized redirect URIs.', 'wpmudev-plugin-test' ),
            __( 'Paste the client ID and secret into the form on this page, then save.', 'wpmudev-plugin-test' ),
            __( 'Click “Authenticate with Google Drive” to complete the connection.', 'wpmudev-plugin-test' ),
        ] ),
        []
    );

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
		return () => {
			if ( redirectCopyTimerRef.current ) {
				clearTimeout( redirectCopyTimerRef.current );
				redirectCopyTimerRef.current = null;
			}
		};
	}, [] );

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
	if ( isAuthenticated && isManageView ) {
		loadFiles();
	}
}, [ isAuthenticated, isManageView ] );

useEffect( () => {
	if ( ! isAuthenticated || ! isManageView ) {
		return undefined;
	}

	if ( initialSearchSyncRef.current ) {
		initialSearchSyncRef.current = false;
		return undefined;
	}

	if ( skipNextSearchRef.current ) {
		skipNextSearchRef.current = false;
		return undefined;
	}

	if ( searchDebounceRef.current ) {
		clearTimeout( searchDebounceRef.current );
	}

	const trimmed = searchTerm.trim();
	const delay = trimmed ? 400 : 250;

	searchDebounceRef.current = setTimeout( () => {
		loadFiles( { append: false, folderId: currentFolder?.id || '', search: trimmed, order: sortOrder } );
	}, delay );

	return () => {
		if ( searchDebounceRef.current ) {
			clearTimeout( searchDebounceRef.current );
			searchDebounceRef.current = null;
		}
	};
}, [ searchTerm, isAuthenticated, isManageView ] );

useEffect( () => {
	if ( ! isAuthenticated || ! isManageView ) {
		return;
	}

	if ( initialSortSyncRef.current ) {
		initialSortSyncRef.current = false;
		return;
	}

	skipNextSearchRef.current = true;
	resetRenameState();
	setOpenMenuId( null );
	loadFiles( {
		append: false,
		folderId: currentFolder?.id || '',
		search: searchTerm.trim(),
		order: sortOrder,
	} );
}, [ sortOrder, isAuthenticated, isManageView ] );

    useEffect( () => {
        if ( ! openMenuId ) {
            return undefined;
        }

        const handleDocumentClick = () => {
            setOpenMenuId( null );
        };

        const handleEscape = ( event ) => {
            if ( event.key === 'Escape' ) {
                setOpenMenuId( null );
                resetRenameState();
            }
        };

        document.addEventListener( 'click', handleDocumentClick );
        document.addEventListener( 'keyup', handleEscape );

        return () => {
            document.removeEventListener( 'click', handleDocumentClick );
            document.removeEventListener( 'keyup', handleEscape );
        };
    }, [ openMenuId ] );

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

    const handleRevokeAuth = async () => {
        if ( revokingAuth ) {
            return;
        }

        setRevokingAuth( true );
        try {
            const response = await apiFetch( {
                path: getRestUrl( driveConfig.restEndpointRevoke ),
                method: 'DELETE',
            } );

            if ( ! response?.success ) {
                throw new Error( response?.message || __( 'Unable to revoke authentication.', 'wpmudev-plugin-test' ) );
            }

            const message = response?.message || __( 'Google Drive access revoked successfully.', 'wpmudev-plugin-test' );
            showNotice( message );
            driveConfig.authStatus = false;
            setIsAuthenticated( false );
            setFiles( [] );
            setNextPageToken( null );
            setFolderStack( [ { id: null, name: __( 'My Drive', 'wpmudev-plugin-test' ) } ] );
            setSearchTerm( '' );
            setUploadFile( null );
            setUploadProgress( null );
            setFileInputKey( Date.now() );
            resetRenameState();
            setOpenMenuId( null );
            setPreviewFailures( {} );
            initialSearchSyncRef.current = true;
            skipNextSearchRef.current = false;
            initialSortSyncRef.current = true;
            setSortOrder( 'desc' );
        } catch ( error ) {
            showNotice( error?.message || __( 'Unable to revoke authentication.', 'wpmudev-plugin-test' ), 'error' );
        } finally {
            setRevokingAuth( false );
        }
    };

    const loadFiles = async ( {
        append = false,
        pageToken = '',
        folderId,
        search,
        order,
    } = {} ) => {
        if ( ! isAuthenticated || ! isManageView ) {
            return;
        }

        const effectiveFolderId = typeof folderId !== 'undefined' && folderId !== null
            ? folderId
            : ( currentFolder?.id || '' );
        const effectiveSearch = typeof search !== 'undefined' && search !== null
            ? search
            : searchTerm;
        const normalizedSearch = effectiveSearch ? effectiveSearch.trim() : '';
        const effectiveOrder = typeof order !== 'undefined' && order !== null
            ? order
            : sortOrder;

        setFilesLoading( true );
        try {
            if ( ! append ) {
                setNextPageToken( null );
                if ( ! pageToken ) {
                    setFiles( [] );
                }
            }

            const query = new URLSearchParams();
            if ( pageToken ) {
                query.append( 'page_token', pageToken );
            }
            if ( effectiveFolderId ) {
                query.append( 'folder_id', effectiveFolderId );
            }
            if ( normalizedSearch ) {
                query.append( 'search', normalizedSearch );
            }
            if ( effectiveOrder ) {
                query.append( 'order', effectiveOrder );
            }

            const response = await apiFetch( {
                path: getRestUrl(
                    driveConfig.restEndpointFiles,
                    query.toString()
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

    const selectUploadFile = ( file ) => {
        if ( file ) {
            setUploadFile( file );
            setUploadProgress( null );
            setFileInputKey( Date.now() );
        }
    };

	const handleSelectFile = () => {
		if ( fileInputRef.current ) {
			fileInputRef.current.click();
		}
	};

	const handleChooseButtonClick = ( event ) => {
		event.stopPropagation();
		handleSelectFile();
	};

	const handleInputClick = ( event ) => {
		event.stopPropagation();
	};

    const handleFileInputChange = ( event ) => {
        const file = event.target.files?.[ 0 ];
        if ( file ) {
            selectUploadFile( file );
        } else {
            setUploadFile( null );
        }
    };

    const handleDropzoneKeyDown = ( event ) => {
        if ( event.key === 'Enter' || event.key === ' ' ) {
            event.preventDefault();
            handleSelectFile();
        }
    };

const handleDragOver = ( event ) => {
    event.preventDefault();
    event.stopPropagation();
    if ( event.dataTransfer ) {
        event.dataTransfer.dropEffect = 'copy';
    }
    setIsDraggingOver( true );
};

const handleDragLeave = ( event ) => {
    event.preventDefault();
    event.stopPropagation();
    const nextTarget = event.relatedTarget;
    if ( nextTarget && event.currentTarget.contains( nextTarget ) ) {
        return;
    }
    setIsDraggingOver( false );
};

    const handleDrop = ( event ) => {
        event.preventDefault();
        event.stopPropagation();
        setIsDraggingOver( false );

        const droppedFile = event.dataTransfer?.files?.[ 0 ];
        if ( droppedFile ) {
            selectUploadFile( droppedFile );
        }
    };

    const handleClearSelectedFile = () => {
        setUploadFile( null );
        setUploadProgress( null );
        if ( fileInputRef.current ) {
            fileInputRef.current.value = '';
        }
    };

    const handleMenuToggle = ( event, fileId ) => {
        event.preventDefault();
        event.stopPropagation();
        setOpenMenuId( ( current ) => ( current === fileId ? null : fileId ) );
    };

	const handleCopyRedirect = async () => {
		if ( ! redirectUri ) {
			return;
		}

		if ( redirectCopyTimerRef.current ) {
			clearTimeout( redirectCopyTimerRef.current );
			redirectCopyTimerRef.current = null;
		}

		try {
			if ( navigator?.clipboard?.writeText ) {
				await navigator.clipboard.writeText( redirectUri );
			} else {
				const helperInput = document.createElement( 'input' );
				helperInput.setAttribute( 'type', 'text' );
				helperInput.setAttribute( 'readonly', 'readonly' );
				helperInput.value = redirectUri;
				document.body.appendChild( helperInput );
				helperInput.select();
				document.execCommand( 'copy' );
				document.body.removeChild( helperInput );
			}

			setRedirectCopied( true );
			redirectCopyTimerRef.current = setTimeout( () => {
				setRedirectCopied( false );
				redirectCopyTimerRef.current = null;
			}, 3500 );
		} catch ( error ) {
			showNotice(
				__( 'Unable to copy the redirect URI automatically. Please copy it manually.', 'wpmudev-plugin-test' ),
				'error'
			);
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
        if ( currentFolder?.id ) {
            formData.append( 'parent_id', currentFolder.id );
        }

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
                loadFiles( { append: false, folderId: currentFolder?.id || '', order: sortOrder, search: searchTerm.trim() } );
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
                    parent_id: currentFolder?.id || '',
                },
            } );

            if ( ! response?.success ) {
                throw new Error( response?.message || __( 'Unable to create folder.', 'wpmudev-plugin-test' ) );
            }

            showNotice( __( 'Folder created successfully.', 'wpmudev-plugin-test' ) );
            setFolderName( '' );
            loadFiles( { append: false, folderId: currentFolder?.id || '', search: searchTerm.trim(), order: sortOrder } );
        } catch ( error ) {
			showNotice( error?.message || __( 'Unable to create folder.', 'wpmudev-plugin-test' ), 'error' );
		} finally {
			setCreatingFolder( false );
		}
	};

	const handleRefreshFiles = () => {
		resetRenameState();
		loadFiles( {
			append: false,
			folderId: currentFolder?.id || '',
			search: searchTerm.trim(),
			order: sortOrder,
		} );
	};

    const resetRenameState = () => {
        setRenameTarget( null );
        setRenameValue( '' );
        setRenaming( false );
    };

    const handleOpenFolder = ( folder ) => {
        resetRenameState();
        setOpenMenuId( null );
        skipNextSearchRef.current = true;
        setFolderStack( ( prev ) => [ ...prev, { id: folder.id, name: folder.name } ] );
        setSearchTerm( '' );
        loadFiles( { append: false, folderId: folder.id, search: '', order: sortOrder } );
    };

    const handleBreadcrumbClick = ( index ) => {
        if ( index === folderStack.length - 1 ) {
            return;
        }

        const targetStack = folderStack.slice( 0, index + 1 );
        resetRenameState();
        setFolderStack( targetStack );

        const target = targetStack[ targetStack.length - 1 ];
        loadFiles( { append: false, folderId: target?.id || '', search: searchTerm.trim(), order: sortOrder } );
    };

    const handleRenameInit = ( file ) => {
        setOpenMenuId( null );
        setRenameTarget( file.id );
        setRenameValue( file.name );
    };

    const handleRenameCancel = () => {
        resetRenameState();
    };

    const handleRenameSubmit = async ( fileId ) => {
        const newName = renameValue.trim();

        if ( newName === '' ) {
            showNotice( __( 'A name is required.', 'wpmudev-plugin-test' ), 'error' );
            return;
        }

        setRenaming( true );
        try {
            const response = await apiFetch( {
                path: getRestUrl( driveConfig.restEndpointRename ),
                method: 'POST',
                data: {
                    file_id: fileId,
                    name: newName,
                },
            } );

            if ( ! response?.success || ! response?.file ) {
                throw new Error( response?.message || __( 'Unable to rename the item.', 'wpmudev-plugin-test' ) );
            }

            setFiles( ( current ) => current.map( ( item ) => ( item.id === fileId ? response.file : item ) ) );
            showNotice( __( 'Item renamed successfully.', 'wpmudev-plugin-test' ) );
            resetRenameState();
        } catch ( error ) {
            showNotice( error?.message || __( 'Unable to rename the item.', 'wpmudev-plugin-test' ), 'error' );
        } finally {
            setRenaming( false );
        }
    };

    const handleDelete = async ( file ) => {
        setOpenMenuId( null );
        const confirmation = window.confirm(
        sprintf(
            __( 'Are you sure you want to delete “%s”? This action cannot be undone.', 'wpmudev-plugin-test' ),
            file.name
        )
        );

        if ( ! confirmation ) {
            return;
        }

        setDeletingId( file.id );
        try {
            const response = await apiFetch( {
                path: getRestUrl(
                    driveConfig.restEndpointDelete,
                    `file_id=${ encodeURIComponent( file.id ) }`
                ),
                method: 'DELETE',
            } );

            if ( ! response?.success ) {
                throw new Error( response?.message || __( 'Unable to delete the item.', 'wpmudev-plugin-test' ) );
            }

            resetRenameState();
            setFiles( ( current ) => current.filter( ( item ) => item.id !== file.id ) );
            showNotice(
                file.mimeType === 'application/vnd.google-apps.folder'
                    ? __( 'Folder deleted.', 'wpmudev-plugin-test' )
                    : __( 'File deleted.', 'wpmudev-plugin-test' )
            );
        } catch ( error ) {
            showNotice( error?.message || __( 'Unable to delete the item.', 'wpmudev-plugin-test' ), 'error' );
        } finally {
            setDeletingId( null );
        }
    };

	const handleLoadMore = () => {
		if ( ! nextPageToken ) {
			return;
		}

		setOpenMenuId( null );
		loadFiles( {
			append: true,
			pageToken: nextPageToken,
			folderId: currentFolder?.id || '',
			search: searchTerm.trim(),
			order: sortOrder,
		} );
	};

    const handleSearchSubmit = () => {
        const trimmed = searchTerm.trim();
        skipNextSearchRef.current = true;
        resetRenameState();
        setSearchTerm( trimmed );
        loadFiles( {
            append: false,
            folderId: currentFolder?.id || '',
            search: trimmed,
            order: sortOrder,
        } );
    };

    const handleSearchKeyDown = ( event ) => {
        if ( event.key === 'Enter' ) {
            event.preventDefault();
            handleSearchSubmit();
        }
    };

    const isFolderItem = ( file ) => file?.mimeType === 'application/vnd.google-apps.folder';

    const getFileExtension = ( name = '' ) => {
        const parts = name.split( '.' );
        return parts.length > 1 ? parts.pop().toUpperCase() : '';
    };

	const getFileTypeLabel = ( file ) => {
		if ( isFolderItem( file ) ) {
			return __( 'Folder', 'wpmudev-plugin-test' );
		}

		return file?.mimeType || __( 'File', 'wpmudev-plugin-test' );
	};

	const getPreviewSource = ( file ) => {
		if ( file?.thumbnailLink ) {
			return file.thumbnailLink.replace( /=s\d+/g, '=s256' );
		}

		if ( file?.iconLink ) {
			return file.iconLink;
		}

		return '';
	};

	const handlePreviewError = ( fileId ) => {
		setPreviewFailures( ( current ) => ( {
			...current,
			[ fileId ]: true,
		} ) );
	};

	const renderPreviewGraphic = ( file, previewSrc, extension, previewFailed ) => {
        if ( previewSrc && ! previewFailed ) {
            return (
                <img
                    src={ previewSrc }
                    alt={ sprintf( __( 'Preview of %s', 'wpmudev-plugin-test' ), file.name ) }
                    onError={ () => handlePreviewError( file.id ) }
                />
            );
        }

        if ( isFolderItem( file ) ) {
            return (
                <div className="drive-file-card__vector drive-file-card__vector--folder" aria-hidden="true">
                    <svg viewBox="0 0 96 72" role="img" focusable="false">
                        <path d="M8 22c0-4.418 3.582-8 8-8h26l8 8h38c4.418 0 8 3.582 8 8v26c0 4.418-3.582 8-8 8H16c-4.418 0-8-3.582-8-8V22z" fill="#1d4ed8" />
                        <path d="M8 22c0-4.418 3.582-8 8-8h26l6 6H8z" fill="#60a5fa" />
                    </svg>
                </div>
            );
        }

        return (
            <div className="drive-file-card__vector drive-file-card__vector--file" aria-hidden="true">
                <svg viewBox="0 0 60 76" role="img" focusable="false">
                    <path d="M12 4h24l14 14v46c0 4.418-3.582 8-8 8H12c-4.418 0-8-3.582-8-8V12c0-4.418 3.582-8 8-8z" fill="#eff6ff" />
                    <path d="M36 4v12c0 3.314 2.686 6 6 6h12" fill="#bfdbfe" />
                    <path d="M12 52h36v4H12zm0 10h24v4H12z" fill="#94a3b8" />
                </svg>
                <span className="drive-file-card__file-ext">{ extension || __( 'FILE', 'wpmudev-plugin-test' ) }</span>
            </div>
        );
    };


	const renderCredentialsPanel = () => {
		const scopeChips = (
			<div className="drive-card__scopes">
				{ scopes.map( ( scope ) => (
					<span className="drive-chip" key={ scope }>
						{ formatScopeLabel( scope ) }
					</span>
				) ) }
			</div>
		);

		const cardDescription = createInterpolateElement(
			__(
				"Generate OAuth credentials inside the <a>Google Cloud Console</a>, then store them here to enable Drive tools.",
				"wpmudev-plugin-test"
			),
			{
				a: (
					<a
						href="https://console.cloud.google.com/apis/credentials"
						target="_blank"
						rel="noreferrer"
					/>
				),
			}
		);

		const clientIdHelp = createInterpolateElement(
			__(
				"Copy the OAuth Client ID from <a>Google Cloud Console</a> after enabling the Drive API.",
				"wpmudev-plugin-test"
			),
			{
				a: (
					<a
						href="https://console.cloud.google.com/apis/credentials"
						target="_blank"
						rel="noreferrer"
					/>
				),
			}
		);

		const clientSecretHelp = createInterpolateElement(
			__(
				"Paste the matching Client Secret from your Google OAuth credentials screen.",
				"wpmudev-plugin-test"
			),
			{
				a: (
					<a
						href="https://console.cloud.google.com/apis/credentials"
						target="_blank"
						rel="noreferrer"
					/>
				),
			}
		);

		return (
			<Card className="drive-card drive-card--credentials" elevated>
				<CardHeader className="drive-card__header">
					<div>
						<CardTitle>{ __( "Google API Credentials", "wpmudev-plugin-test" ) }</CardTitle>
						<CardDescription>{ cardDescription }</CardDescription>
					</div>
					{ hasCredentials && (
						<Button
							variant="ghost"
							size="sm"
							onClick={ () => setShowCredentials( ( prev ) => ! prev ) }
						>
							{ showCredentials
								? __( "Collapse form", "wpmudev-plugin-test" )
								: __( "Edit credentials", "wpmudev-plugin-test" ) }
						</Button>
					) }
				</CardHeader>

				{ showCredentials ? (
					<>
						<CardContent className="drive-card__content drive-card__content--form">
							<FormField
								label={ __( "Client ID", "wpmudev-plugin-test" ) }
								labelFor="drive-client-id"
								description={ clientIdHelp }
							>
								<Input
									id="drive-client-id"
									value={ credentials.clientId }
									onChange={ ( event ) => handleFieldChange( "clientId", event.target.value ) }
									placeholder="XXXXXXXXXXXXXXXXXX.apps.googleusercontent.com"
									autoComplete="off"
								/>
							</FormField>
							<FormField
								label={ __( "Client Secret", "wpmudev-plugin-test" ) }
								labelFor="drive-client-secret"
								description={ clientSecretHelp }
							>
								<Input
									id="drive-client-secret"
									type="password"
									value={ credentials.clientSecret }
									onChange={ ( event ) => handleFieldChange( "clientSecret", event.target.value ) }
									autoComplete="off"
								/>
							</FormField>
							<FormField
								label={ __( "Redirect URI", "wpmudev-plugin-test" ) }
								labelFor="drive-redirect-uri"
								description={ __(
									"Add this URL to the list of authorized redirect URIs in Google Cloud Console.",
									"wpmudev-plugin-test"
								) }
							>
								<div className="drive-redirect-field">
									<Input
										id="drive-redirect-uri"
										value={ redirectUri }
										readOnly
										onFocus={ ( event ) => event.target.select() }
									/>
									<Button
										variant="secondary"
										size="sm"
										type="button"
										onClick={ handleCopyRedirect }
										disabled={ ! redirectUri }
									>
										{ redirectCopied
											? __( "Copied", "wpmudev-plugin-test" )
											: __( "Copy URI", "wpmudev-plugin-test" ) }
									</Button>
								</div>
								{ redirectCopied && (
									<span className="drive-redirect-field__status">
										{ __( "Redirect URI copied to clipboard.", "wpmudev-plugin-test" ) }
									</span>
								) }
							</FormField>
						</CardContent>
						<CardFooter className="drive-card__footer">
							<div className="drive-card__footer-meta">
								<span className="drive-card__footer-label">
									{ __( "OAuth scopes", "wpmudev-plugin-test" ) }
								</span>
								{ scopeChips }
							</div>
							<Button
								variant="primary"
								onClick={ handleSaveCredentials }
								isLoading={ savingCredentials }
								disabled={ savingCredentials }
							>
								{ savingCredentials
									? __( "Saving…", "wpmudev-plugin-test" )
									: __( "Save Credentials", "wpmudev-plugin-test" ) }
							</Button>
						</CardFooter>
					</>
				) : (
					<CardContent className="drive-card__content drive-card__content--summary">
						<p className="drive-card__description">
							{ __(
								"Credentials stored securely. Re-open the form above whenever you rotate keys or need to verify them.",
								"wpmudev-plugin-test"
							) }
						</p>
						<div className="drive-redirect-summary">
							<div className="drive-redirect-summary__header">
								<span className="drive-card__footer-label">
									{ __( "Redirect URI", "wpmudev-plugin-test" ) }
								</span>
								<Button
									variant="ghost"
									size="sm"
									type="button"
									onClick={ handleCopyRedirect }
									disabled={ ! redirectUri }
								>
									{ redirectCopied
										? __( "Copied", "wpmudev-plugin-test" )
										: __( "Copy URI", "wpmudev-plugin-test" ) }
								</Button>
							</div>
							<code className="drive-redirect-summary__value">{ redirectUri }</code>
							{ redirectCopied && (
								<span className="drive-redirect-field__status drive-redirect-summary__status">
									{ __( "Redirect URI copied to clipboard.", "wpmudev-plugin-test" ) }
								</span>
							) }
						</div>
						{ scopeChips }
					</CardContent>
				) }
			</Card>
		);
	};

	const renderUploadPanel = () => (
		<Card className="drive-card drive-card--upload" elevated>
			<CardHeader>
				<CardTitle>{ __( "Upload to Drive", "wpmudev-plugin-test" ) }</CardTitle>
				<CardDescription>
					{ __( "Drag & drop a file or browse your device. Files land in the folder you’re currently viewing.", "wpmudev-plugin-test" ) }
				</CardDescription>
			</CardHeader>
			<CardContent className="drive-card__content drive-card__content--upload">
				<div
					role="button"
					tabIndex={ 0 }
					className={ [
						"drive-upload-dropzone",
						isDraggingOver && "is-dragging",
						uploadFile && "has-file",
					].filter( Boolean ).join( " " ) }
					onClick={ handleSelectFile }
					onDragOver={ handleDragOver }
					onDragLeave={ handleDragLeave }
					onDrop={ handleDrop }
					onKeyDown={ handleDropzoneKeyDown }
				>
					<div className="drive-upload-dropzone__inner">
						<div className="drive-upload-dropzone__icon" aria-hidden="true">
							<span>📤</span>
						</div>
						<strong className="drive-upload-dropzone__title">
							{ __( "Drop files or browse", "wpmudev-plugin-test" ) }
						</strong>
						<p className="drive-upload-dropzone__helper">
							{ __( "Accepted formats match your Drive allowlist. Upload limit:", "wpmudev-plugin-test" ) } { uploadLimitLabel }
						</p>
						<Button variant="secondary" size="sm" onClick={ handleChooseButtonClick }>
							{ __( "Choose a file", "wpmudev-plugin-test" ) }
						</Button>
					</div>
					<input
						key={ fileInputKey }
						ref={ fileInputRef }
						className="drive-upload-input"
						type="file"
						onClick={ handleInputClick }
						onChange={ handleFileInputChange }
						aria-hidden="true"
						tabIndex={ -1 }
					/>
				</div>

				{ uploadFile && (
					<div className="drive-upload-selection">
						<div>
							<strong>{ uploadFile.name }</strong>
							<p>
								{ sprintf(
									__( "Size: %s", "wpmudev-plugin-test" ),
									formatBytes( uploadFile.size )
								) }
							</p>
						</div>
						<Button variant="link" size="sm" onClick={ handleClearSelectedFile }>
							{ __( "Clear selection", "wpmudev-plugin-test" ) }
						</Button>
					</div>
				) }

				{ typeof uploadProgress === "number" && (
					<div className="drive-upload-progress">
						<span>{ sprintf( __( "Uploading… %d%%", "wpmudev-plugin-test" ), uploadProgress ) }</span>
					</div>
				) }
			</CardContent>
			<CardFooter className="drive-card__footer">
				<div className="drive-card__footer-meta">
					<span className="drive-card__footer-label">
						{ __( "Destination folder", "wpmudev-plugin-test" ) }
					</span>
					<div className="drive-card__breadcrumbs">
						{ folderStack.map( ( crumb, index ) => (
							<span key={ crumb.id || `crumb-${ index }` }>{ crumb.name }</span>
						) ) }
					</div>
				</div>
				<Button
					variant="primary"
					onClick={ handleUpload }
					isLoading={ uploading }
					disabled={ uploading || ! uploadFile }
				>
					{ uploading
						? __( "Uploading…", "wpmudev-plugin-test" )
						: __( "Upload to Drive", "wpmudev-plugin-test" ) }
				</Button>
			</CardFooter>
		</Card>
	);

	const renderFolderPanel = () => (
		<Card className="drive-card drive-card--folder" elevated>
			<CardHeader>
				<CardTitle>{ __( "Create New Folder", "wpmudev-plugin-test" ) }</CardTitle>
				<CardDescription>
					{ __( "Spin up a folder inside your active Drive location and instantly refresh the listing below.", "wpmudev-plugin-test" ) }
				</CardDescription>
			</CardHeader>
			<CardContent className="drive-card__content drive-card__content--form">
				<FormField
					label={ __( "Folder name", "wpmudev-plugin-test" ) }
					labelFor="drive-folder-name"
				>
					<Input
						id="drive-folder-name"
						value={ folderName }
						onChange={ ( event ) => setFolderName( event.target.value ) }
						placeholder={ __( "Marketing uploads", "wpmudev-plugin-test" ) }
					/>
				</FormField>
			</CardContent>
			<CardFooter className="drive-card__footer drive-card__footer--compact">
				<div className="drive-card__footer-meta">
					<span className="drive-card__footer-label">
						{ __( "Parent folder", "wpmudev-plugin-test" ) }
					</span>
					<div className="drive-card__breadcrumbs">
						{ currentFolder?.name || __( "My Drive", "wpmudev-plugin-test" ) }
					</div>
				</div>
				<Button
					variant="secondary"
					onClick={ handleCreateFolder }
					isLoading={ creatingFolder }
					disabled={ creatingFolder || ! folderName.trim() }
				>
					{ creatingFolder
						? __( "Creating…", "wpmudev-plugin-test" )
						: __( "Create Folder", "wpmudev-plugin-test" ) }
				</Button>
			</CardFooter>
		</Card>
	);

    const renderFiles = () => {
        const breadcrumbItems = folderStack;
        const searchActive = Boolean( searchTerm );

        return (
            <div className="drive-browser">
                <div className="drive-files-toolbar">
                    <nav className="drive-breadcrumbs" aria-label={ __( 'Google Drive breadcrumb', 'wpmudev-plugin-test' ) }>
                        { breadcrumbItems.map( ( crumb, index ) => {
                            const isCurrent = index === breadcrumbItems.length - 1;
                            return (
                                <button
                                    key={ crumb.id || `crumb-${ index }` }
                                    type="button"
                                    className={ `drive-breadcrumbs__item${ isCurrent ? ' is-current' : '' }` }
                                    onClick={ () => handleBreadcrumbClick( index ) }
                                    disabled={ isCurrent }
                                >
                                    { crumb.name }
                                </button>
                            );
                        } ) }
                    </nav>
                    <div className="drive-files-toolbar__actions">
                        { folderStack.length > 1 && (
                            <Button
                                variant="secondary"
                                size="sm"
                                onClick={ () => handleBreadcrumbClick( folderStack.length - 2 ) }
                            >
                                { __( 'Up One Level', 'wpmudev-plugin-test' ) }
                            </Button>
                        ) }
						<div className="drive-files-toolbar__sort">
							<span className="drive-sort-label">{ __( 'Sort by', 'wpmudev-plugin-test' ) }</span>
							<Select
								id="drive-sort-order"
								className="drive-sort-select"
								value={ sortOrder }
								onChange={ ( event ) => setSortOrder( event.target.value ) }
							>
								<option value="desc">{ __( 'Latest first', 'wpmudev-plugin-test' ) }</option>
								<option value="asc">{ __( 'Oldest first', 'wpmudev-plugin-test' ) }</option>
							</Select>
                        </div>
                        <div className="drive-files-toolbar__search">
                            <Input
                                value={ searchTerm }
                                onChange={ ( event ) => {
                                    setOpenMenuId( null );
                                    setSearchTerm( event.target.value );
                                } }
                                onKeyDown={ handleSearchKeyDown }
                                placeholder={ __( 'Search this folder', 'wpmudev-plugin-test' ) }
                                aria-label={ __( 'Search Drive', 'wpmudev-plugin-test' ) }
                            />
                            <Button
                                variant="secondary"
                                size="sm"
                                onClick={ handleSearchSubmit }
                                isLoading={ filesLoading && searchActive }
                            >
                                { __( 'Search', 'wpmudev-plugin-test' ) }
                            </Button>
                            { searchTerm && (
                                <Button
                                    variant="link"
                                    size="sm"
                                    className="drive-files-toolbar__clear"
                                    onClick={ () => {
                                        skipNextSearchRef.current = true;
                                        resetRenameState();
                                        setOpenMenuId( null );
                                        setSearchTerm( '' );
                                        loadFiles( { append: false, folderId: currentFolder?.id || '', search: '' } );
                                    } }
                                >
                                    { __( 'Clear', 'wpmudev-plugin-test' ) }
                                </Button>
                            ) }
                        </div>
                    </div>
                </div>

                { filesLoading && ! files.length && (
                    <div className="drive-loading">
                        <Spinner />
                        <p>{ __( 'Loading files…', 'wpmudev-plugin-test' ) }</p>
                    </div>
                ) }

                { ! filesLoading && ! files.length && (
                    <div className="drive-empty-state">
                        <p className="drive-empty-state__title">
                            { __( 'No Drive files yet', 'wpmudev-plugin-test' ) }
                        </p>
                        <p className="drive-empty-state__helper">
                            { __( 'Upload a file or refresh the list to view items from Google Drive.', 'wpmudev-plugin-test' ) }
                        </p>
                    </div>
                ) }

                { !! files.length && (
                    <>
                        <div className="drive-files-grid">
                            { files.map( ( file ) => {
                                const isFolder = isFolderItem( file );
                                const rawPreviewSrc = getPreviewSource( file );
                                const hasPreviewFailure = Boolean( previewFailures[ file.id ] );
                                const previewSrc = hasPreviewFailure ? '' : rawPreviewSrc;
                                const extension = isFolder ? '' : getFileExtension( file.name );
                                const canRename = !! ( file?.capabilities?.canRename );
                                const canDelete = !! ( file?.capabilities?.canDelete || file?.capabilities?.canTrash );
                                const isRenaming = renameTarget === file.id;
                                const isDeleting = deletingId === file.id;
                                const disableRenameButton = ! canRename || ( renameTarget && renameTarget !== file.id ) || isRenaming;
                                const modifiedLabel = file.modifiedTime
                                    ? new Date( file.modifiedTime ).toLocaleString()
                                    : __( 'Unknown date', 'wpmudev-plugin-test' );
                                const sizeLabel = ! isFolder && file.size ? formatBytes( file.size ) : '';

                                return (
                                    <div key={ file.id } className="drive-file-card">
                                        <div className={ `drive-file-card__preview${ isFolder ? ' drive-file-card__preview--folder' : '' }` }>
                                            { renderPreviewGraphic( file, previewSrc, extension, hasPreviewFailure ) }
                                            <span className="drive-file-card__badge">
                                                { isFolder ? __( 'Folder', 'wpmudev-plugin-test' ) : __( 'File', 'wpmudev-plugin-test' ) }
                                            </span>
                                            <button
                                                type="button"
                                                className="drive-file-card__menu-trigger"
                                                aria-expanded={ openMenuId === file.id }
                                                aria-label={ __( 'File actions', 'wpmudev-plugin-test' ) }
                                                onClick={ ( event ) => handleMenuToggle( event, file.id ) }
                                            >
                                                <span aria-hidden="true" />
                                                <span aria-hidden="true" />
                                                <span aria-hidden="true" />
                                            </button>
                                        </div>

                    { openMenuId === file.id && (
                        <div
                            className="drive-file-card__menu"
                            role="menu"
                            onClick={ ( event ) => event.stopPropagation() }
                        >
                            { isFolder ? (
                                <button
                                    type="button"
                                    className="drive-file-card__menu-item"
                                    onClick={ () => {
                                        setOpenMenuId( null );
                                        handleOpenFolder( file );
                                    } }
                                >
                                    { __( 'Open Folder', 'wpmudev-plugin-test' ) }
                                </button>
                            ) : (
                                <button
                                    type="button"
                                    className="drive-file-card__menu-item"
                                    onClick={ () => {
                                        setOpenMenuId( null );
                                        handleDownload( file.id, file.name );
                                    } }
                                >
                                    { __( 'Download', 'wpmudev-plugin-test' ) }
                                </button>
                            ) }
                            { file.webViewLink && (
                                <button
                                    type="button"
                                    className="drive-file-card__menu-item"
                                    onClick={ () => {
                                        setOpenMenuId( null );
                                        window.open( file.webViewLink, '_blank', 'noopener,noreferrer' );
                                    } }
                                >
                                    { __( 'Open in Drive', 'wpmudev-plugin-test' ) }
                                </button>
                            ) }
                            <button
                                type="button"
                                className="drive-file-card__menu-item"
                                onClick={ () => {
                                    if ( disableRenameButton ) {
                                        return;
                                    }
                                    setOpenMenuId( null );
                                    handleRenameInit( file );
                                } }
                                disabled={ disableRenameButton }
                            >
                                { __( 'Rename', 'wpmudev-plugin-test' ) }
                            </button>
                            <button
                                type="button"
                                className="drive-file-card__menu-item drive-file-card__menu-item--danger"
                                onClick={ () => handleDelete( file ) }
                                disabled={ ! canDelete || isRenaming }
                            >
                                { __( 'Delete', 'wpmudev-plugin-test' ) }
                            </button>
                        </div>
                    ) }

                    <div className="drive-file-card__meta">
                        { isRenaming ? (
												<div className="drive-file-card__rename">
													<Input
														value={ renameValue }
														onChange={ ( event ) => setRenameValue( event.target.value ) }
														autoFocus
														aria-label={ __( 'Rename item', 'wpmudev-plugin-test' ) }
													/>
													<div className="drive-file-card__rename-actions">
														<Button
															variant="primary"
															size="sm"
															onClick={ () => handleRenameSubmit( file.id ) }
															isLoading={ renaming }
														>
															{ __( 'Save', 'wpmudev-plugin-test' ) }
														</Button>
														<Button variant="link" size="sm" onClick={ handleRenameCancel }>
															{ __( 'Cancel', 'wpmudev-plugin-test' ) }
														</Button>
													</div>
												</div>
											) : (
                                                <>
                                                    <h3 className="drive-file-card__name">{ file.name }</h3>
                                                    <p className="drive-file-card__details">
                                                        { getFileTypeLabel( file ) } • { modifiedLabel }
                                                        { sizeLabel ? ` • ${ sizeLabel }` : '' }
                                                    </p>
                                                </>
                                            ) }

                                        </div>
                                    </div>
                                );
                            } ) }
                        </div>
                        { nextPageToken && (
                            <div className="drive-files-more">
                                <Button variant="secondary" onClick={ handleLoadMore } isLoading={ filesLoading }>
                                    { __( 'Load More Files', 'wpmudev-plugin-test' ) }
                                </Button>
                            </div>
                        ) }
                    </>
                ) }
            </div>
        );
    };

	const pageTitle = isManageView
		? __( 'Manage Google Drive', 'wpmudev-plugin-test' )
		: __( 'Google Drive Test', 'wpmudev-plugin-test' );
	const pageDescription = isManageView
		? __( 'Upload files, browse folders, and manage Drive content directly from WordPress.', 'wpmudev-plugin-test' )
		: __( 'Manage Google Drive credentials, authenticate, and test Drive file operations.', 'wpmudev-plugin-test' );
	const heroEyebrow = isManageView
		? __( 'Drive workspace', 'wpmudev-plugin-test' )
		: __( 'Drive integration', 'wpmudev-plugin-test' );
	const noticeVariant = notice.type === 'error'
		? 'error'
		: ( notice.type === 'warning' ? 'info' : 'success' );
	const noticeTitle = notice.type === 'error'
		? __( 'Something went wrong', 'wpmudev-plugin-test' )
		: notice.type === 'warning'
			? __( 'Heads up', 'wpmudev-plugin-test' )
			: __( 'Success', 'wpmudev-plugin-test' );


	return (
		<div className="shadcn-admin drive-admin">
			<div className="drive-shell">
				<header className="drive-hero">
					<div className="drive-hero__copy">
						<span className="drive-hero__eyebrow">{ heroEyebrow }</span>
						<h1>{ pageTitle }</h1>
						<p>{ pageDescription }</p>
						<div className="drive-hero__status">
							<StatusBadge tone={ connectionSummary.tone }>
								{ isAuthenticated ? __( 'Connected', 'wpmudev-plugin-test' ) : __( 'Needs setup', 'wpmudev-plugin-test' ) }
							</StatusBadge>
							<div className="drive-hero__status-text">
								<strong>{ connectionSummary.title }</strong>
								<span>{ connectionSummary.description }</span>
							</div>
						</div>
					</div>
					<Card className="drive-hero__card" elevated>
						<CardHeader>
							<CardTitle>{ __( 'Connection overview', 'wpmudev-plugin-test' ) }</CardTitle>
							<CardDescription>{ __( 'Quick stats to confirm your Drive handshake is healthy.', 'wpmudev-plugin-test' ) }</CardDescription>
						</CardHeader>
						<CardContent>
							<dl className="shadcn-key-value drive-hero__stats">
								<div>
									<dt>{ __( 'Authentication', 'wpmudev-plugin-test' ) }</dt>
									<dd>{ isAuthenticated ? __( 'Active', 'wpmudev-plugin-test' ) : __( 'Pending', 'wpmudev-plugin-test' ) }</dd>
								</div>
								<div>
									<dt>{ __( 'Stored credentials', 'wpmudev-plugin-test' ) }</dt>
									<dd>{ hasCredentials ? __( 'Available', 'wpmudev-plugin-test' ) : __( 'Missing', 'wpmudev-plugin-test' ) }</dd>
								</div>
								<div>
									<dt>{ __( 'Upload limit', 'wpmudev-plugin-test' ) }</dt>
									<dd>{ uploadLimitLabel }</dd>
								</div>
								<div>
									<dt>{ __( 'Scopes requested', 'wpmudev-plugin-test' ) }</dt>
									<dd>{ scopes.length }</dd>
								</div>
							</dl>
						</CardContent>
					</Card>
				</header>

				{ notice.message && (
					<Alert
						variant={ noticeVariant }
						title={ noticeTitle }
						description={ notice.message }
						className="drive-alert"
					>
						<Button variant="link" size="sm" onClick={ clearNotice }>
							{ __( 'Dismiss', 'wpmudev-plugin-test' ) }
						</Button>
					</Alert>
				) }

				{ isSettingsView && (
					<section className="drive-summary-grid">
						{ statusCards.map( ( card ) => (
							<Card
								key={ card.id }
								className={ 'drive-summary drive-summary--' + card.tone }
								elevated
							>
								<CardContent>
									<span className="drive-summary__label">{ card.label }</span>
									<span className="drive-summary__value">{ card.value }</span>
									<p className="drive-summary__helper">{ card.helper }</p>
								</CardContent>
							</Card>
						) ) }
					</section>
				) }

				<div className="drive-layout">
					<div className="drive-layout__column">
						{ isSettingsView && renderCredentialsPanel() }
						{ isSettingsView && renderDriveActions() }
						{ isManageView && renderFolderPanel() }
					</div>
					{ isManageView && (
						<div className="drive-layout__column">
							{ renderUploadPanel() }
						</div>
					) }
				</div>

				{ isManageView && (
					<Card className="drive-card drive-card--files" elevated>
						<CardHeader className="drive-card__header">
							<div>
								<CardTitle>{ __( 'Your Drive Files', 'wpmudev-plugin-test' ) }</CardTitle>
								<CardDescription>{ __( 'Browse the live Drive listing. Search, sort, open in Drive, or download instantly.', 'wpmudev-plugin-test' ) }</CardDescription>
							</div>
							<Button
								variant="ghost"
								size="sm"
								onClick={ handleRefreshFiles }
								isLoading={ filesLoading }
							>
								{ filesLoading ? __( 'Refreshing…', 'wpmudev-plugin-test' ) : __( 'Refresh list', 'wpmudev-plugin-test' ) }
							</Button>
						</CardHeader>
						<CardContent className="drive-card__content drive-card__content--files">
							{ renderFiles() }
						</CardContent>
					</Card>
				) }
			</div>
		</div>
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
	const renderDriveActions = () => {
		const heading = isAuthenticated
			? __( "Manage Google Drive Connection", "wpmudev-plugin-test" )
			: __( "Connect to Google Drive", "wpmudev-plugin-test" );
		const description = isAuthenticated
			? __(
					"Your Google account is connected. Revoke access to disconnect this site or re-authenticate if needed.",
					"wpmudev-plugin-test"
			  )
			: __(
					"Authenticate with your Google account to unlock uploads, folder creation, and live browsing of Drive files.",
					"wpmudev-plugin-test"
			  );
		const authLabel = authInProgress
			? __( "Redirecting…", "wpmudev-plugin-test" )
			: (
				isAuthenticated
					? __( "Re-authenticate with Google Drive", "wpmudev-plugin-test" )
					: __( "Authenticate with Google Drive", "wpmudev-plugin-test" )
			);

		return (
			<Card className="drive-card drive-card--auth" elevated>
				<CardHeader>
					<CardTitle>{ heading }</CardTitle>
					<CardDescription>{ description }</CardDescription>
				</CardHeader>
				<CardContent className="drive-card__content drive-card__content--actions">
					<div className="drive-card__actions">
						<Button
							variant="primary"
							onClick={ handleAuth }
							isLoading={ authInProgress }
							disabled={ authInProgress || revokingAuth }
						>
							{ authLabel }
						</Button>
						{ isAuthenticated && (
							<Button
								variant="destructive"
								onClick={ handleRevokeAuth }
								isLoading={ revokingAuth }
								disabled={ revokingAuth || authInProgress }
							>
								{ revokingAuth
									? __( "Revoking…", "wpmudev-plugin-test" )
									: __( "Revoke Google Drive Access", "wpmudev-plugin-test" ) }
							</Button>
						) }
					</div>
					<p className="drive-card__hint">
						{ __( "You’ll be redirected to Google for OAuth approval. Upon success, the page refreshes automatically.", "wpmudev-plugin-test" ) }
					</p>
				</CardContent>
			</Card>
		);
	};
